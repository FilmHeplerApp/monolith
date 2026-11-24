<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\DTOs\ProviderAttribute;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Application\Media\DTOs\TitleImageIngestionRequest;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Infrastructure\Import\Sinks\CatalogCandidateSink;
use App\Infrastructure\Persistence\Eloquent\Media\EloquentTitleImageIngestionOutbox;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use App\Infrastructure\Persistence\Eloquent\Models\Media\TitleImageIngestion;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesImageOutboxImportSchema;
use Tests\Support\SingleProviderClientFactory;
use Tests\Support\UsesIsolatedPostgresSchema;
use Tests\TestCase;

#[Group('pgsql')]
#[Group('catalog_import')]
final class PostgresImportImageOutboxTest extends TestCase
{
    use CreatesImageOutboxImportSchema;
    use UsesIsolatedPostgresSchema;


    private ?Connection $peer = null;


    #[Test]
    public function it_commits_titles_outbox_and_checkpoint_in_one_transaction(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        config()->set('import.checkpoint_every', 1);
        $this->app->instance(ProviderClientFactoryContract::class, new SingleProviderClientFactory(new MockProviderClient([
            $this->record('1', 'Cowboy Bebop'),
            $this->record('2', 'Trigun'),
        ])));
        $this->app->instance(CandidateSinkContract::class, new FailingOnSecondFlushSink(app(CatalogCandidateSink::class)));

        try {
            app(ImportTitlesFromProvider::class)->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('The second batch must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Failure after image planning.', $exception->getMessage());
        }

        $this->assertDatabaseCount(Title::TABLE_NAME, 1);
        self::assertSame(
            [(string) Title::query()->value(Title::FIELD_CANONICAL_KEY)],
            DB::table(TitleImageIngestion::TABLE_NAME)->pluck(TitleImageIngestion::FIELD_CANONICAL_KEY)->all(),
        );
        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 1]);
        $this->assertDatabaseCount('import_processed_titles', 1);
    }

    #[Test]
    public function it_increments_the_revision_on_conflict(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 10:00:00'));
        $this->insertTitle('a|anime');
        $this->outbox()->enqueue([new TitleImageIngestionRequest('a|anime', 'https://cdn.example/a.jpg', null)]);
        $this->travel(1)->minutes();

        $this->outbox()->enqueue([new TitleImageIngestionRequest('a|anime', null, 'https://cdn.example/b.jpg')]);

        $row = TitleImageIngestion::query()->sole();
        self::assertSame(2, $row->revision);
        self::assertNull($row->poster_url);
        self::assertSame('https://cdn.example/b.jpg', $row->banner_url);
        self::assertTrue($row->created_at->equalTo(Carbon::parse('2026-11-17 10:00:00')));
        self::assertTrue($row->updated_at->equalTo(Carbon::parse('2026-11-17 10:01:00')));
    }

    #[Test]
    public function it_rejects_a_row_without_urls(): void
    {
        $this->insertTitle('a|anime');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('title_image_ingestions_has_url_check');

        DB::table(TitleImageIngestion::TABLE_NAME)->insert([
            TitleImageIngestion::FIELD_CANONICAL_KEY => 'a|anime',
            TitleImageIngestion::FIELD_CREATED_AT => now(),
            TitleImageIngestion::FIELD_UPDATED_AT => now(),
        ]);
    }

    #[Test]
    public function it_cascades_outbox_rows_with_a_deleted_title(): void
    {
        $this->insertTitle('a|anime');
        $this->insertTitle('b|anime');
        $this->outbox()->enqueue([
            new TitleImageIngestionRequest('a|anime', 'https://cdn.example/a.jpg', null),
            new TitleImageIngestionRequest('b|anime', 'https://cdn.example/b.jpg', null),
        ]);

        DB::table(Title::TABLE_NAME)->where(Title::FIELD_CANONICAL_KEY, 'a|anime')->delete();

        self::assertSame(['b|anime'], DB::table(TitleImageIngestion::TABLE_NAME)->pluck(TitleImageIngestion::FIELD_CANONICAL_KEY)->all());
    }

    #[Test]
    public function it_hides_uncommitted_outbox_rows_from_the_relay(): void
    {
        $this->insertTitle('a|anime');

        DB::transaction(function (): void {
            $this->outbox()->enqueue([new TitleImageIngestionRequest('a|anime', 'https://cdn.example/a.jpg', null)]);

            self::assertSame(1, DB::table(TitleImageIngestion::TABLE_NAME)->count());
            self::assertSame(0, $this->peer()->table(TitleImageIngestion::TABLE_NAME)->count());
        });

        self::assertSame(1, $this->peer()->table(TitleImageIngestion::TABLE_NAME)->count());
    }

    #[Test]
    public function it_does_not_delete_a_row_updated_by_a_concurrent_import(): void
    {
        $this->insertTitle('a|anime');
        $this->outbox()->enqueue([new TitleImageIngestionRequest('a|anime', 'https://cdn.example/a.jpg', null)]);
        [$pending] = $this->outbox()->pending(0, 10);

        $this->peer()->table(TitleImageIngestion::TABLE_NAME)
            ->where(TitleImageIngestion::FIELD_ID, $pending->id)
            ->update([
                TitleImageIngestion::FIELD_POSTER_URL => 'https://cdn.example/a-new.jpg',
                TitleImageIngestion::FIELD_REVISION => DB::raw('revision + 1'),
            ]);

        self::assertFalse($this->outbox()->acknowledge($pending->id, $pending->revision));
        $this->assertDatabaseHas(TitleImageIngestion::TABLE_NAME, [
            TitleImageIngestion::FIELD_POSTER_URL => 'https://cdn.example/a-new.jpg',
            TitleImageIngestion::FIELD_REVISION => 2,
        ]);
        self::assertTrue($this->outbox()->acknowledge($pending->id, 2));
    }


    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('This regression requires PostgreSQL.');
        }

        $this->createImageOutboxImportSchema();
    }

    protected function tearDown(): void
    {
        if ($this->peer !== null) {
            DB::purge($this->peer->getName());
            $this->peer = null;
        }

        parent::tearDown();
    }


    private function outbox(): EloquentTitleImageIngestionOutbox
    {
        return new EloquentTitleImageIngestionOutbox;
    }

    private function peer(): Connection
    {
        return $this->peer ??= DB::connectUsing(DB::getDefaultConnection().'_peer', DB::connection()->getConfig());
    }

    private function record(string $externalId, string $titleEn): ProviderTitleRecord
    {
        return new ProviderTitleRecord(ProviderSource::Mock, $externalId, [
            'title' => LocalizedText::create(null, $titleEn),
            'description' => LocalizedText::create('Описание', null),
            'year' => 1998,
            'durationMinutes' => 24,
            'rating' => 8.5,
            'posterUrl' => 'https://cdn.example/poster.jpg',
            'bannerUrl' => null,
            'type' => TitleContentType::ANIME->value,
            'status' => TitleStatus::RELEASED->value,
            'format' => TitleFormat::TV,
            'ratingCount' => 100,
            'attributes' => [new ProviderAttribute('genres', LocalizedText::create('Экшен', 'Action'))],
        ]);
    }
}
