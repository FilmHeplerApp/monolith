<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Catalog\DTOs\PreparedCatalogDto;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\DTOs\ProviderAttribute;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ImportRunMode;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Services\CatalogPacker;
use App\Application\Import\Services\CatalogTitlePackDataFactory;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Services\TitleKeyResolver;
use App\Infrastructure\Import\Sinks\CatalogCandidateSink;
use App\Infrastructure\Persistence\Eloquent\Bulk\Catalog\UnresolvedCatalogReferenceException;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeDefinition;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeOption;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\TitleAttribute;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesCatalogSchema;
use Tests\Support\SingleProviderClientFactory;
use Tests\TestCase;

final class CatalogCandidateSinkTest extends TestCase
{
    use CreatesCatalogSchema;


    private const array IMPORT_MIGRATIONS = [
        '2026_09_24_000001_create_import_runs_table.php',
        '2026_09_24_000002_create_import_rejections_table.php',
        '2026_10_22_105000_add_snapshot_id_to_import_runs_table.php',
        '2026_10_22_110000_create_import_processed_titles_table.php',
        '2026_10_29_090000_add_owner_token_to_import_runs.php',
    ];


    #[Test]
    public function it_saves_accepted_and_flagged_titles_and_reimports_without_duplicates(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        config()->set('import.checkpoint_every', 2);
        $this->bindRecords(
            $this->record('1', 'Cowboy Bebop'),
            $this->record('2', 'Trigun'),
            $this->record('3', 'Outlaw Star', posterUrl: null),
        );

        $first = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
        $uuids = Title::query()->orderBy(Title::FIELD_ID)->pluck(Title::FIELD_UUID)->all();
        $second = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        foreach ([$first, $second] as $result) {
            self::assertSame(2, $result->counters->accepted);
            self::assertSame(1, $result->counters->flagged);
            $this->assertDatabaseHas('import_runs', ['id' => $result->runId, 'status' => 'completed', 'checkpoint' => 3]);
        }
        self::assertSame($uuids, Title::query()->orderBy(Title::FIELD_ID)->pluck(Title::FIELD_UUID)->all());
        $this->assertDatabaseCount(Title::TABLE_NAME, 3);
        $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, 6);
        $this->assertDatabaseCount(AttributeOption::TABLE_NAME, 2);
        $this->assertDatabaseHas(Title::TABLE_NAME, [Title::FIELD_TITLE_EN => 'Cowboy Bebop', Title::FIELD_IS_INCOMPLETE => false]);
        $this->assertDatabaseHas(Title::TABLE_NAME, [Title::FIELD_TITLE_EN => 'Outlaw Star', Title::FIELD_IS_INCOMPLETE => true]);
        self::assertSame(
            ['Экшен'],
            Title::query()->where(Title::FIELD_TITLE_EN, 'Trigun')->firstOrFail()->attributes()->whereNotNull('value_array')->firstOrFail()->value_array,
        );
    }

    #[Test]
    public function it_rolls_back_a_failed_batch_and_resumes_without_duplicates(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        config()->set('import.checkpoint_every', 1);
        $this->bindRecords($this->record('1', 'Cowboy Bebop'), $this->record('2', 'Trigun'));
        $writer = app(CatalogBulkWriterContract::class);
        $this->app->instance(CatalogBulkWriterContract::class, new FailingCatalogWriter($writer, failOnWrite: 2));

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('The second batch must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Catalog write failed.', $exception->getMessage());
        }
        $this->assertDatabaseCount(Title::TABLE_NAME, 1);
        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 1]);

        $this->app->instance(CatalogBulkWriterContract::class, $writer);
        $result = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, resume: true);

        self::assertSame(ImportRunMode::Resumed, $result->mode);
        $this->assertDatabaseHas('import_runs', ['id' => $result->runId, 'status' => 'completed', 'checkpoint' => 2]);
        $this->assertDatabaseCount(Title::TABLE_NAME, 2);
        $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, 4);
    }

    #[Test]
    public function it_does_not_save_titles_with_colliding_keys_and_logs_the_collision(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        Log::spy();
        $this->bindRecords(
            $this->record('1', 'Cowboy Bebop'),
            $this->record('2', 'Cowboy  Bebop!'),
            $this->record('3', 'Trigun'),
        );

        $result = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        self::assertSame(3, $result->counters->accepted);
        $this->assertDatabaseCount(Title::TABLE_NAME, 1);
        $this->assertDatabaseHas(Title::TABLE_NAME, [Title::FIELD_TITLE_EN => 'Trigun']);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $message === 'Import title key collision.'
                && $context['identities'] === [
                    ['source' => 'mock', 'external_id' => '1'],
                    ['source' => 'mock', 'external_id' => '2'],
                ],
        );
    }

    #[Test]
    public function it_fails_the_run_when_attribute_definitions_are_not_seeded(): void
    {
        $this->bindRecords($this->record('1', 'Cowboy Bebop'));

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('Unseeded definitions must stop the import.');
        } catch (UnresolvedCatalogReferenceException) {
        }

        $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, 0);
        $this->assertDatabaseCount(Title::TABLE_NAME, 0);
        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 0]);
    }

    #[Test]
    public function it_keeps_seeded_attribute_definitions_untouched(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        AttributeDefinition::query()->where(AttributeDefinition::FIELD_CODE, 'genres')->update([
            AttributeDefinition::FIELD_NAME_RU => 'Жанры (админ)',
            AttributeDefinition::FIELD_IS_FILTERABLE => false,
        ]);
        $this->bindRecords($this->record('1', 'Cowboy Bebop'));

        $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, 6);
        $this->assertDatabaseHas(AttributeDefinition::TABLE_NAME, [
            AttributeDefinition::FIELD_CODE => 'genres',
            AttributeDefinition::FIELD_NAME_RU => 'Жанры (админ)',
            AttributeDefinition::FIELD_IS_FILTERABLE => false,
        ]);
        $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, 2);
    }

    #[Test]
    public function it_does_not_write_an_empty_or_aborted_buffer(): void
    {
        $writer = $this->createMock(CatalogBulkWriterContract::class);
        $writer->expects(self::never())->method('write');
        $sink = new CatalogCandidateSink(
            app(TitleKeyResolver::class),
            app(CatalogTitlePackDataFactory::class),
            new CatalogPacker,
            $writer,
        );

        $sink->flush();
        $sink->consume($this->candidate(), FilterDecision::accept());
        $sink->abort();
        $sink->flush();
        $sink->finish();
    }


    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::IMPORT_MIGRATIONS as $name) {
            (require database_path('migrations/'.$name))->up();
        }

        $this->createCatalogSchema();
    }


    private function importer(): ImportTitlesFromProvider
    {
        return app(ImportTitlesFromProvider::class);
    }

    private function bindRecords(ProviderTitleRecord ...$records): void
    {
        $this->app->instance(ProviderClientFactoryContract::class, new SingleProviderClientFactory(new MockProviderClient($records)));
    }

    private function record(
        string  $externalId,
        string  $titleEn,
        ?string $posterUrl = 'https://example.test/poster.jpg',
    ): ProviderTitleRecord {
        return new ProviderTitleRecord(ProviderSource::Mock, $externalId, [
            'title' => LocalizedText::create(null, $titleEn),
            'description' => LocalizedText::create('Описание', null),
            'year' => 1998,
            'durationMinutes' => 24,
            'rating' => 8.5,
            'posterUrl' => $posterUrl,
            'bannerUrl' => null,
            'type' => TitleContentType::ANIME->value,
            'status' => TitleStatus::RELEASED->value,
            'format' => TitleFormat::TV,
            'ratingCount' => 100,
            'attributes' => [
                new ProviderAttribute('genres', LocalizedText::create('Экшен', 'Action')),
                new ProviderAttribute('studios', LocalizedText::create(null, 'Sunrise')),
            ],
        ]);
    }

    private function candidate(): TitleCandidate
    {
        return new TitleCandidate(
            source: 'mock',
            externalId: '1',
            title: LocalizedText::create(null, 'Cowboy Bebop'),
            description: null,
            type: TitleContentType::ANIME,
            status: TitleStatus::RELEASED,
            releaseYear: 1998,
            providerScore: null,
            providerScoreCount: null,
            durationMinutes: null,
            posterUrl: null,
            bannerUrl: null,
        );
    }
}

final class FailingCatalogWriter implements CatalogBulkWriterContract
{
    private int $writes = 0;


    public function __construct(
        private readonly CatalogBulkWriterContract $writer,
        private readonly int                       $failOnWrite,
    ) {}

    public function write(PreparedCatalogDto $preparedCatalogDto): void
    {
        $this->writer->write($preparedCatalogDto);

        if (++$this->writes === $this->failOnWrite) {
            throw new RuntimeException('Catalog write failed.');
        }
    }
}
