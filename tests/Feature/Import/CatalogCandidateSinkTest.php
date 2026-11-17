<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Catalog\DTOs\PreparedCatalogDto;
use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\DTOs\ProviderAttribute;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ImportRunMode;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Services\CatalogImportBatchSaver;
use App\Application\Import\Services\CatalogTitlePackDataFactory;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Services\TitleKeyResolver;
use App\Infrastructure\Import\Exceptions\CatalogAttributeDefinitionException;
use App\Infrastructure\Import\Sinks\CatalogCandidateSink;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeDefinition;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeOption;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\TitleAttribute;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CommittedShikimoriDump;
use Tests\Support\CreatesCatalogSchema;
use Tests\Support\SingleProviderClientFactory;
use Tests\Support\UsesIsolatedPostgresSchema;
use Tests\TestCase;

final class CatalogCandidateSinkTest extends TestCase
{
    use CreatesCatalogSchema;
    use UsesIsolatedPostgresSchema;


    private const array IMPORT_MIGRATIONS = [
        '2026_09_24_000001_create_import_runs_table.php',
        '2026_09_24_000002_create_import_rejections_table.php',
        '2026_10_22_105000_add_snapshot_id_to_import_runs_table.php',
        '2026_10_22_110000_create_import_processed_titles_table.php',
        '2026_10_29_090000_add_owner_token_to_import_runs.php',
    ];


    #[Test]
    #[Group('catalog_import')]
    public function it_preserves_admin_descriptions_and_refreshes_the_sync_timestamp(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-13 12:00:00'));
        $record = $this->record('1', 'Cowboy Bebop');
        $this->bindRecords($record);
        $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
        DB::table('titles')->update([
            'description_ru' => 'Ручное описание',
            'description_en' => 'Manual description',
            'short_plot_ru' => 'Ручной сюжет',
            'updated_by' => 'admin',
        ]);
        $this->travel(10)->minutes();
        $updated = new ProviderTitleRecord($record->source, $record->externalId, array_replace($record->payload, ['status' => 'ongoing']));
        $this->bindRecords($updated);
        $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
        $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        $this->assertDatabaseHas('titles', [
            'description_ru' => 'Ручное описание',
            'description_en' => 'Manual description',
            'short_plot_ru' => 'Ручной сюжет',
            'updated_by' => 'admin',
            'status' => 'ongoing',
        ]);
        self::assertTrue(Title::query()->firstOrFail()->last_synced_at->equalTo(now()));
    }

    #[Test]
    #[Group('catalog_import')]
    public function it_keeps_a_title_completed_by_an_admin_complete_on_reimport(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $record = $this->record('1', 'Cowboy Bebop');
        $this->bindRecords(new ProviderTitleRecord($record->source, $record->externalId, array_replace($record->payload, ['description' => null])));
        $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
        $this->assertDatabaseHas('titles', ['description_ru' => null, 'is_incomplete' => true, 'updated_by' => 'process']);
        DB::table('titles')->update([
            'description_ru' => 'Описание от админа',
            'is_incomplete' => false,
            'updated_by' => 'admin',
        ]);

        $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        $this->assertDatabaseHas('titles', [
            'description_ru' => 'Описание от админа',
            'is_incomplete' => false,
            'updated_by' => 'admin',
        ]);
    }

    #[Test]
    #[Group('catalog_import')]
    public function it_updates_imported_descriptions_without_clearing_a_short_plot(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-13 12:00:00'));
        $record = $this->record('1', 'Cowboy Bebop');
        $this->bindRecords($record);
        $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
        self::assertTrue(Title::query()->firstOrFail()->last_synced_at->equalTo(now()));
        DB::table('titles')->update(['short_plot_ru' => 'Существующий сюжет']);
        $this->travel(10)->minutes();
        $this->bindRecords(new ProviderTitleRecord($record->source, $record->externalId, array_replace($record->payload, [
            'description' => LocalizedText::create('Новое описание', 'New description'),
        ])));
        $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        $this->assertDatabaseHas('titles', ['description_ru' => 'Новое описание', 'description_en' => 'New description', 'short_plot_ru' => 'Существующий сюжет', 'updated_by' => 'process']);
        self::assertTrue(Title::query()->firstOrFail()->last_synced_at->equalTo(now()));
    }

    #[Test]
    #[Group('catalog_import')]
    public function it_rolls_back_last_synced_at_when_the_catalog_write_fails(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-13 12:00:00'));
        $record = $this->record('1', 'Cowboy Bebop');
        $this->bindRecords($record);
        $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
        $before = Title::query()->firstOrFail()->last_synced_at;
        $this->travel(10)->minutes();
        $this->app->instance(CatalogBulkWriterContract::class, new FailingCatalogWriter(app(CatalogBulkWriterContract::class), 1));

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('The write must fail after updating the title.');
        } catch (RuntimeException $exception) {
            self::assertSame('Catalog write failed.', $exception->getMessage());
        }

        self::assertTrue(Title::query()->firstOrFail()->last_synced_at->equalTo($before));
        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 0]);
    }

    #[Test]
    #[Group('catalog_import')]
    public function it_refuses_incompatible_definitions_before_writing_the_catalog(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        DB::table('attribute_definitions')->where('code', 'genres')->update(['value_type' => 'number']);
        $this->bindRecords($this->record('1', 'Cowboy Bebop'));
        $writer = $this->createMock(CatalogBulkWriterContract::class);
        $writer->expects(self::never())->method('write');
        $this->app->instance(CatalogBulkWriterContract::class, $writer);

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('An incompatible definition must fail the batch.');
        } catch (CatalogAttributeDefinitionException $exception) {
            self::assertSame('Catalog attribute definition [anime:genres] has type [number], expected [array].', $exception->getMessage());
        }

        $this->assertDatabaseCount('titles', 0);
        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 0]);
        $this->assertDatabaseCount('import_processed_titles', 0);
    }

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
    #[Group('catalog_import')]
    public function it_does_not_log_a_collision_when_the_batch_rolls_back(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        Log::spy();
        $this->bindRecords(
            $this->record('1', 'Cowboy Bebop'),
            $this->record('2', 'Cowboy  Bebop!'),
            $this->record('3', 'Trigun'),
        );
        $this->app->instance(CandidateSinkContract::class, new FailingAfterFlushSink(app(CatalogCandidateSink::class)));

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('The batch must fail after the sink has written the catalog.');
        } catch (RuntimeException $exception) {
            self::assertSame('Failure after catalog flush.', $exception->getMessage());
        }

        $this->assertDatabaseCount(Title::TABLE_NAME, 0);
        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 0]);
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    #[Group('catalog_import')]
    public function it_imports_the_shikimori_fixture_dump_into_the_catalog_and_reimports_without_duplicates(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $directory = sys_get_temp_dir().'/catalog-sink-fixture-'.bin2hex(random_bytes(8));
        CommittedShikimoriDump::configure(CommittedShikimoriDump::create(
            $directory,
            (string) file_get_contents(base_path('static/Fixtures/Shikimori/animes.ndjson')),
        ));

        try {
            $first = $this->importer()->execute(ProviderSource::Shikimori, ProviderDataSource::Dump);
            $uuids = Title::query()->orderBy(Title::FIELD_ID)->pluck(Title::FIELD_UUID)->all();
            $second = $this->importer()->execute(ProviderSource::Shikimori, ProviderDataSource::Dump);
        } finally {
            File::deleteDirectory($directory);
        }

        foreach ([$first, $second] as $result) {
            self::assertSame(286, $result->counters->fetched);
            self::assertSame(159, $result->counters->accepted);
            self::assertSame(107, $result->counters->flagged);
            self::assertSame(20, $result->counters->rejected);
            $this->assertDatabaseHas('import_runs', ['id' => $result->runId, 'status' => 'completed', 'checkpoint' => 286]);
        }
        self::assertSame($uuids, Title::query()->orderBy(Title::FIELD_ID)->pluck(Title::FIELD_UUID)->all());
        $this->assertDatabaseCount(Title::TABLE_NAME, 266);
        self::assertSame(107, Title::query()->where(Title::FIELD_IS_INCOMPLETE, true)->count());
        $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, 6);
        $this->assertDatabaseCount(AttributeOption::TABLE_NAME, 148);
        $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, 716);
        self::assertSame(
            [
                'genres' => ['Экшен', 'Фантастика'],
                'tags' => ['Удостоено наград', 'Взрослые персонажи', 'Космос'],
                'studios' => ['Sunrise'],
            ],
            $this->attributesOf('Cowboy Bebop'),
        );
        self::assertSame(['Детское', 'Сёнен'], $this->attributesOf('Дораэмон (2005)', Title::FIELD_TITLE_RU)['target_audience']);
        $this->assertDatabaseHas(AttributeOption::TABLE_NAME, [AttributeOption::FIELD_VALUE_RU => 'Удостоено наград', AttributeOption::FIELD_VALUE_EN => 'Award Winning']);
    }

    #[Test]
    public function it_fails_the_run_when_attribute_definitions_are_not_seeded(): void
    {
        $this->bindRecords($this->record('1', 'Cowboy Bebop'));

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('Unseeded definitions must stop the import.');
        } catch (CatalogAttributeDefinitionException $exception) {
            self::assertStringContainsString('Missing catalog attribute definition [anime:genres]', $exception->getMessage());
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
            app(CatalogImportBatchSaver::class, ['writer' => $writer]),
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

    private function attributesOf(string $title, string $column = Title::FIELD_TITLE_EN): array
    {
        $titleId = Title::query()->where($column, $title)->value(Title::FIELD_ID);

        return DB::table(TitleAttribute::TABLE_NAME)
            ->join(AttributeDefinition::TABLE_NAME, AttributeDefinition::TABLE_NAME.'.'.AttributeDefinition::FIELD_ID, '=', TitleAttribute::TABLE_NAME.'.'.TitleAttribute::FIELD_ATTRIBUTE_ID)
            ->where(TitleAttribute::TABLE_NAME.'.'.TitleAttribute::FIELD_TITLE_ID, $titleId)
            ->orderBy(AttributeDefinition::TABLE_NAME.'.'.AttributeDefinition::FIELD_ORDER)
            ->get([AttributeDefinition::TABLE_NAME.'.'.AttributeDefinition::FIELD_CODE, TitleAttribute::TABLE_NAME.'.'.TitleAttribute::FIELD_VALUE_ARRAY])
            ->mapWithKeys(static fn (object $row): array => [
                $row->{AttributeDefinition::FIELD_CODE} => json_decode($row->{TitleAttribute::FIELD_VALUE_ARRAY}, true, 512, JSON_THROW_ON_ERROR),
            ])
            ->all();
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

final class FailingAfterFlushSink implements CandidateSinkContract
{
    public function __construct(private readonly CandidateSinkContract $sink) {}

    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        $this->sink->consume($candidate, $decision);
    }

    public function flush(): void
    {
        $this->sink->flush();

        throw new RuntimeException('Failure after catalog flush.');
    }

    public function finish(): void
    {
        $this->flush();
    }

    public function abort(): void
    {
        $this->sink->abort();
    }
}
