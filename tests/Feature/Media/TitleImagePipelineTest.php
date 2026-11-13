<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\Contracts\TitleImageRequestRepositoryContract;
use App\Application\Media\DTOs\DownloadedImage;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\DTOs\StoredImage;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\ImageStorageException;
use App\Application\Media\Exceptions\UnsupportedImageException;
use App\Application\Media\Jobs\ProcessTitleImageJob;
use App\Application\Media\UseCases\IngestTitleImageHandler;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Infrastructure\Media\Outbox\PublishTitleImagesOutbox;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesCatalogSchema;
use Tests\Support\SingleProviderClientFactory;
use Tests\TestCase;

#[Group('catalog_media')]
final class TitleImagePipelineTest extends TestCase
{
    use CreatesCatalogSchema;


    private ?string $schema = null;
    private ?string $previousConnection = null;
    private array $uploaded = [];
    private array $deleted = [];


    #[Test]
    public function it_records_independent_assets_atomically_without_dispatching_during_import(): void
    {
        Bus::fake();
        $this->import('https://example.test/poster', 'https://example.test/banner');
        $this->import('https://example.test/poster', 'https://example.test/banner');

        $this->assertDatabaseCount('title_image_requests', 2);
        $this->assertDatabaseCount('title_image_outbox', 2);
        $this->assertDatabaseHas('title_image_requests', ['kind' => 'poster', 'status' => 'pending', 'version' => 1]);
        $this->assertDatabaseHas('title_image_requests', ['kind' => 'banner', 'status' => 'pending', 'version' => 1]);
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_does_not_create_requests_for_missing_urls(): void
    {
        $this->import(null, null);

        $this->assertDatabaseCount('title_image_requests', 0);
        $this->assertDatabaseCount('title_image_outbox', 0);
    }

    #[Test]
    public function it_rolls_back_media_and_catalog_when_the_import_journal_fails(): void
    {
        $armed = true;
        DB::listen(static function ($query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'insert into "import_processed_titles"')) {
                $armed = false;
                throw new RuntimeException('Journal failed after recording media');
            }
        });
        try {
            $this->import();
            self::fail('The journal failure must roll back the batch.');
        } catch (RuntimeException $exception) {
            self::assertSame('Journal failed after recording media', $exception->getMessage());
        }
        $this->assertDatabaseCount('titles', 0);
        $this->assertDatabaseCount('title_image_requests', 0);
        $this->assertDatabaseCount('title_image_outbox', 0);
        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 0]);
    }

    #[Test]
    public function it_publishes_outside_a_transaction_and_redelivers_a_lost_queue_message(): void
    {
        $this->import();
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(static function ($job): void {
            self::assertInstanceOf(ProcessTitleImageJob::class, $job);
            self::assertSame('images', $job->queue);
            self::assertSame(0, DB::transactionLevel());
        });
        $publisher = new PublishTitleImagesOutbox($bus);

        self::assertSame(1, $publisher->publish());
        self::assertSame(0, $publisher->publish());
        $this->travel(361)->seconds();
        self::assertSame(1, $publisher->publish());
        $this->assertDatabaseHas('title_image_outbox', ['publish_attempts' => 2, 'completed_at' => null]);
    }

    #[Test]
    public function it_publishes_waiting_requests_before_redelivering_older_queue_messages(): void
    {
        $this->import('https://example.test/poster', 'https://example.test/banner');
        $ids = DB::table('title_image_outbox')->orderBy('id')->pluck('request_id')->all();
        $published = [];
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(static function ($job) use (&$published): void {
            $published[] = $job->requestId;
        });
        $publisher = new PublishTitleImagesOutbox($bus);
        $publisher->publish(1);
        $this->travel(361)->seconds();
        $publisher->publish(1);

        self::assertSame($ids, $published);
    }

    #[Test]
    public function it_keeps_an_outbox_event_when_the_queue_is_unavailable(): void
    {
        $this->import();
        $bus = $this->createStub(Dispatcher::class);
        $bus->method('dispatch')->willThrowException(new RuntimeException('Queue unavailable'));

        self::assertSame(0, new PublishTitleImagesOutbox($bus)->publish());
        $this->assertDatabaseHas('title_image_outbox', ['last_error' => 'Queue unavailable', 'completed_at' => null]);
        self::assertTrue(now()->lt(DB::table('title_image_outbox')->value('available_at')));
    }

    #[Test]
    public function it_saves_both_poster_variants_and_the_banner_before_marking_them_ready(): void
    {
        $this->import('https://example.test/poster', 'https://example.test/banner');
        $synced = DB::table('titles')->value('last_synced_at');
        $this->media();
        $this->travel(10)->minutes();
        foreach (DB::table('title_image_requests')->get() as $request) {
            new ProcessTitleImageJob((int) $request->id, (int) $request->version)->handle(app(IngestTitleImageHandler::class));
        }
        $title = DB::table('titles')->first();
        self::assertNotNull($title->poster_key);
        self::assertNotNull($title->poster_thumb_key);
        self::assertNotNull($title->banner_key);
        self::assertSame($synced, $title->last_synced_at);
        self::assertSame(2, DB::table('title_image_requests')->where('status', 'ready')->count());
        self::assertSame(2, DB::table('title_image_outbox')->whereNotNull('completed_at')->count());
        self::assertCount(3, $this->uploaded);
        $this->import('https://example.test/poster', 'https://example.test/banner');
        $this->assertDatabaseCount('title_image_outbox', 2);
    }

    #[Test]
    public function it_does_not_download_a_duplicate_or_obsolete_job(): void
    {
        $this->import();
        $request = DB::table('title_image_requests')->first();
        $repo = app(TitleImageRequestRepositoryContract::class);
        self::assertNotNull($repo->claim((int) $request->id, 1));
        $downloader = $this->createMock(ImageDownloaderContract::class);
        $downloader->expects(self::never())->method('download');
        $this->app->instance(ImageDownloaderContract::class, $downloader);

        app(IngestTitleImageHandler::class)->handle((int) $request->id, 1);
        $this->import('https://example.test/new-poster');
        app(IngestTitleImageHandler::class)->handle((int) $request->id, 1);

        $this->assertDatabaseHas('title_image_requests', ['version' => 2, 'status' => 'pending', 'attempts' => 0]);
    }

    #[Test]
    public function it_prevents_an_old_download_from_overwriting_a_new_source_url(): void
    {
        $this->import();
        $request = DB::table('title_image_requests')->first();
        $this->media(function (): DownloadedImage {
            $this->import('https://example.test/new-poster');

            return new DownloadedImage('raw', 'image/jpeg');
        });

        app(IngestTitleImageHandler::class)->handle((int) $request->id, 1);

        $this->assertDatabaseHas('title_image_requests', ['version' => 2, 'status' => 'pending']);
        self::assertNull(DB::table('titles')->value('poster_key'));
        self::assertCount(1, $this->deleted);
        self::assertSame(1, DB::table('title_image_outbox')->whereNull('completed_at')->count());
    }

    #[Test]
    public function it_fences_an_expired_processing_token_even_for_the_same_version(): void
    {
        $this->import();
        $id = (int) DB::table('title_image_requests')->value('id');
        $repo = app(TitleImageRequestRepositoryContract::class);
        $first = $repo->claim($id, 1);
        $this->travel(301)->seconds();
        $second = $repo->claim($id, 1);

        self::assertNotSame($first->token, $second->token);
        self::assertNull($repo->complete($first, ['poster_full' => 'old/full.webp', 'poster_thumb' => 'old/thumb.webp']));
        self::assertSame([], $repo->complete($second, ['poster_full' => 'new/full.webp', 'poster_thumb' => 'new/thumb.webp']));
        self::assertSame('new/full.webp', DB::table('titles')->value('poster_key'));
    }

    #[Test]
    public function it_retries_transient_failures_without_removing_a_working_image(): void
    {
        $this->import();
        DB::table('titles')->update(['poster_key' => 'old/full.webp', 'poster_thumb_key' => 'old/thumb.webp']);
        $this->media(static fn () => throw new ImageDownloadException('Host unavailable', 45));
        $id = (int) DB::table('title_image_requests')->value('id');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            app(IngestTitleImageHandler::class)->handle($id, 1);
            $this->assertDatabaseHas('title_image_requests', ['attempts' => $attempt, 'status' => $attempt === 3 ? 'failed' : 'pending', 'last_error' => 'Host unavailable']);
            self::assertSame('old/full.webp', DB::table('titles')->value('poster_key'));
            $this->travel(46)->seconds();
        }
        self::assertNotNull(DB::table('title_image_outbox')->value('completed_at'));
        $this->import();
        $this->assertDatabaseHas('title_image_requests', ['version' => 2, 'status' => 'pending', 'attempts' => 0]);
    }

    #[Test]
    public function it_does_not_report_unsupported_images_as_successful(): void
    {
        $this->import();
        $this->media(static fn () => throw new UnsupportedImageException('Unsupported media'));
        $id = (int) DB::table('title_image_requests')->value('id');

        app(IngestTitleImageHandler::class)->handle($id, 1);

        $this->assertDatabaseHas('title_image_requests', ['status' => 'failed', 'attempts' => 1, 'last_error' => 'Unsupported media']);
        self::assertNull(DB::table('titles')->value('poster_key'));
    }

    #[Test]
    public function it_cleans_partial_uploads_when_the_thumbnail_fails(): void
    {
        $this->import();
        $this->media();
        $storage = $this->createMock(ImageStorageContract::class);
        $storage->expects(self::exactly(2))->method('put')->willReturnOnConsecutiveCalls(new StoredImage('partial/full.webp'), $this->throwException(new ImageStorageException('Thumbnail upload failed')));
        $storage->expects(self::once())->method('deleteByPrefix');
        $this->app->instance(ImageStorageContract::class, $storage);

        app(IngestTitleImageHandler::class)->handle((int) DB::table('title_image_requests')->value('id'), 1);

        $this->assertDatabaseHas('title_image_requests', ['status' => 'pending', 'last_error' => 'Thumbnail upload failed']);
        self::assertNull(DB::table('titles')->value('poster_key'));
    }

    #[Test]
    public function it_bounds_recovery_of_workers_killed_during_processing(): void
    {
        $this->import();
        $id = (int) DB::table('title_image_requests')->value('id');
        $repo = app(TitleImageRequestRepositoryContract::class);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            self::assertNotNull($repo->claim($id, 1));
            $this->travel(301)->seconds();
        }
        self::assertNull($repo->claim($id, 1));
        $this->assertDatabaseHas('title_image_requests', ['status' => 'failed', 'attempts' => 3]);
        self::assertNotNull(DB::table('title_image_outbox')->value('completed_at'));
    }

    #[Test]
    public function it_runs_the_publisher_command_and_validates_its_limit(): void
    {
        Bus::fake();
        $this->import();
        $this->artisan('images:publish-outbox', ['--limit' => 1])->assertSuccessful()->expectsOutputToContain('Published image requests: 1');
        Bus::assertDispatched(ProcessTitleImageJob::class);
        $this->artisan('images:publish-outbox', ['--limit' => 0])->assertFailed();
    }

    #[Test]
    public function it_processes_a_real_fixture_and_stores_webp_variants(): void
    {
        $disk = Storage::fake(config('images.disk'));
        $downloader = $this->createStub(ImageDownloaderContract::class);
        $downloader->method('download')->willReturn(new DownloadedImage(file_get_contents(base_path('tests/Fixtures/Media/poster_177.jpg')), 'image/jpeg'));
        $this->app->instance(ImageDownloaderContract::class, $downloader);
        $this->import();

        app(IngestTitleImageHandler::class)->handle((int) DB::table('title_image_requests')->value('id'), 1);

        $title = DB::table('titles')->first();
        $disk->assertExists([$title->poster_key, $title->poster_thumb_key]);
        self::assertSame('image/webp', getimagesize($disk->path($title->poster_key))['mime']);
        self::assertSame('image/webp', getimagesize($disk->path($title->poster_thumb_key))['mime']);
        $this->assertDatabaseHas('title_image_requests', ['status' => 'ready']);
    }

    #[Test]
    public function it_keeps_the_banner_independent_of_a_failed_poster(): void
    {
        $this->import('https://example.test/poster', 'https://example.test/banner');
        $this->media(static function (string $url): DownloadedImage {
            if (str_ends_with($url, '/poster')) {
                throw new UnsupportedImageException('Unsupported poster');
            }

            return new DownloadedImage('raw', 'image/jpeg');
        });
        foreach (DB::table('title_image_requests')->get() as $request) {
            app(IngestTitleImageHandler::class)->handle((int) $request->id, 1);
        }

        $this->assertDatabaseHas('title_image_requests', ['kind' => 'poster', 'status' => 'failed']);
        $this->assertDatabaseHas('title_image_requests', ['kind' => 'banner', 'status' => 'ready']);
        self::assertNotNull(DB::table('titles')->value('banner_key'));
        self::assertNull(DB::table('titles')->value('poster_key'));
    }

    #[Test]
    public function it_postpones_redelivery_while_a_worker_owns_an_unexpired_lease(): void
    {
        $this->import();
        $id = (int) DB::table('title_image_requests')->value('id');
        app(TitleImageRequestRepositoryContract::class)->claim($id, 1);
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects(self::never())->method('dispatch');

        self::assertSame(0, new PublishTitleImagesOutbox($bus)->publish());
        self::assertSame(DB::table('title_image_requests')->value('lease_expires_at'), DB::table('title_image_outbox')->value('available_at'));
    }

    #[Test]
    public function it_preserves_published_files_when_database_commit_confirmation_is_lost(): void
    {
        $this->import();
        $this->media();
        $real = app(TitleImageRequestRepositoryContract::class);
        $repo = $this->createStub(TitleImageRequestRepositoryContract::class);
        $repo->method('claim')->willReturnCallback($real->claim(...));
        $repo->method('complete')->willReturnCallback(static function ($work, $keys) use ($real): void {
            $real->complete($work, $keys);

            throw new RuntimeException('Commit confirmation lost');
        });
        $repo->method('fail')->willReturnCallback($real->fail(...));
        $this->app->instance(TitleImageRequestRepositoryContract::class, $repo);

        app(IngestTitleImageHandler::class)->handle((int) DB::table('title_image_requests')->value('id'), 1);

        self::assertSame([], $this->deleted);
        self::assertNotNull(DB::table('titles')->value('poster_key'));
        $this->assertDatabaseHas('title_image_requests', ['status' => 'ready']);
    }

    #[Test]
    public function it_removes_the_previous_managed_images_only_after_successful_replacement(): void
    {
        $this->import();
        $prefix = 'titles/posters/11111111-1111-4111-8111-111111111111';
        DB::table('titles')->update(['poster_key' => $prefix.'/full.webp', 'poster_thumb_key' => $prefix.'/thumb.webp']);
        $this->media();

        app(IngestTitleImageHandler::class)->handle((int) DB::table('title_image_requests')->value('id'), 1);

        self::assertSame([$prefix], $this->deleted);
        self::assertNotSame($prefix.'/full.webp', DB::table('titles')->value('poster_key'));
    }

    #[Test]
    public function it_limits_the_scheduler_mutex_to_five_minutes(): void
    {
        Artisan::call('schedule:list');
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn ($event): bool => str_contains($event->command ?? '', 'images:publish-outbox')));

        self::assertCount(1, $events);
        self::assertTrue($events[0]->withoutOverlapping);
        self::assertSame(5, $events[0]->expiresAt);
    }

    #[Test]
    #[DataProvider('invalidMediaStates')]
    public function it_rejects_unknown_media_states_at_the_database_boundary(string $field, string $value): void
    {
        $this->import();
        $this->expectException(QueryException::class);

        DB::table('title_image_requests')->update([$field => $value]);
    }

    public static function invalidMediaStates(): array
    {
        return ['kind' => ['kind', 'avatar'], 'status' => ['status', 'unknown']];
    }

    #[Test]
    #[Group('pgsql')]
    #[DataProvider('invalidPostgresMediaRows')]
    public function it_rejects_inconsistent_postgresql_media_rows(string $table, array $values): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL CHECK constraints.');
        }
        $this->import();
        $this->expectException(QueryException::class);

        DB::table($table)->update($values);
    }

    public static function invalidPostgresMediaRows(): array
    {
        return [
            'request version' => ['title_image_requests', ['version' => 0]],
            'request attempts' => ['title_image_requests', ['attempts' => -1]],
            'missing processing lease' => ['title_image_requests', ['status' => 'processing']],
            'token outside processing' => ['title_image_requests', ['processing_token' => '11111111-1111-4111-8111-111111111111']],
            'outbox version' => ['title_image_outbox', ['version' => 0]],
            'outbox attempts' => ['title_image_outbox', ['publish_attempts' => -1]],
        ];
    }

    #[Test]
    public function it_can_roll_back_the_media_schema_without_removing_titles(): void
    {
        $this->import();
        $migration = require database_path('migrations/2026_11_13_000002_create_title_image_requests_and_outbox.php');
        $migration->down();
        self::assertFalse(Schema::hasTable('title_image_outbox'));
        self::assertFalse(Schema::hasTable('title_image_requests'));
        $this->assertDatabaseCount('titles', 1);
        $migration->up();
        $this->assertDatabaseCount('title_image_requests', 0);
    }


    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        if (DB::getDriverName() === 'pgsql') {
            $this->previousConnection = DB::getDefaultConnection();
            $this->schema = 'media_pipeline_'.bin2hex(random_bytes(8));
            $config = DB::connection()->getConfig();
            DB::statement('CREATE SCHEMA '.$this->schema);
            $config['search_path'] = $this->schema.', public';
            $config['name'] = $this->schema;
            config(['database.connections.'.$this->schema => $config, 'database.default' => $this->schema]);
        }
        $this->createCatalogSchema();
        foreach ([
            '2026_09_24_000001_create_import_runs_table.php',
            '2026_09_24_000002_create_import_rejections_table.php',
            '2026_10_22_105000_add_snapshot_id_to_import_runs_table.php',
            '2026_10_22_110000_create_import_processed_titles_table.php',
            '2026_10_29_090000_add_owner_token_to_import_runs.php',
        ] as $name) {
            (require database_path('migrations/'.$name))->up();
        }
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            DB::purge($this->schema);
            config(['database.default' => $this->previousConnection]);
            DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
        }
        parent::tearDown();
    }


    private function import(?string $poster = 'https://example.test/poster', ?string $banner = null): void
    {
        $record = new ProviderTitleRecord(ProviderSource::Mock, '1', [
            'title' => LocalizedText::create(null, 'Example title'),
            'description' => LocalizedText::create(null, 'Description'),
            'year' => 2000,
            'durationMinutes' => 24,
            'rating' => null,
            'posterUrl' => $poster,
            'bannerUrl' => $banner,
            'type' => 'anime',
            'status' => 'released',
            'format' => TitleFormat::TV,
        ]);
        $this->app->instance(ProviderClientFactoryContract::class, new SingleProviderClientFactory(new MockProviderClient([$record])));
        app(ImportTitlesFromProvider::class)->execute(ProviderSource::Mock, ProviderDataSource::Api);
    }

    private function media(?callable $download = null): void
    {
        $downloader = $this->createStub(ImageDownloaderContract::class);
        $downloader->method('download')->willReturnCallback($download ?? static function (): DownloadedImage {
            self::assertSame(0, DB::transactionLevel());

            return new DownloadedImage('raw', 'image/jpeg');
        });
        $compressor = $this->createStub(ImageCompressorContract::class);
        $compressor->method('compress')->willReturn(new ProcessedImage('webp', 'image/webp', 10, 10));
        $storage = $this->createStub(ImageStorageContract::class);
        $storage->method('put')->willReturnCallback(function ($bytes, $key): StoredImage {
            self::assertSame(0, DB::transactionLevel());
            $this->uploaded[] = $key;

            return new StoredImage($key);
        });
        $storage->method('deleteByPrefix')->willReturnCallback(function ($prefix): void {
            $this->deleted[] = $prefix;
        });
        $this->app->instance(ImageDownloaderContract::class, $downloader);
        $this->app->instance(ImageCompressorContract::class, $compressor);
        $this->app->instance(ImageStorageContract::class, $storage);
    }
}
