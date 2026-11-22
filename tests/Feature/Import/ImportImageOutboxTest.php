<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\DTOs\ProviderAttribute;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ImportRunMode;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Infrastructure\Import\Sinks\CatalogCandidateSink;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use App\Infrastructure\Persistence\Eloquent\Models\Media\TitleImageIngestion;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CommittedShikimoriDump;
use Tests\Support\CreatesImageOutboxImportSchema;
use Tests\Support\SingleProviderClientFactory;
use Tests\Support\UsesIsolatedPostgresSchema;
use Tests\TestCase;

#[Group('catalog_import')]
final class ImportImageOutboxTest extends TestCase
{
    use CreatesImageOutboxImportSchema;
    use UsesIsolatedPostgresSchema;


    private const string POSTER_URL = 'https://cdn.example/poster.jpg';
    private const string NEW_POSTER_URL = 'https://cdn.example/poster-new.jpg';
    private const string BANNER_URL = 'https://cdn.example/banner.jpg';


    #[Test]
    public function it_writes_outbox_rows_for_a_committed_batch(): void
    {
        $this->bindRecords(
            $this->record('1', 'Cowboy Bebop', bannerUrl: self::BANNER_URL),
            $this->record('2', 'Trigun', posterUrl: 'https://cdn.example/trigun.jpg'),
        );

        $this->import();

        self::assertSame(
            [
                [$this->keyOf('Cowboy Bebop'), self::POSTER_URL, self::BANNER_URL, 1],
                [$this->keyOf('Trigun'), 'https://cdn.example/trigun.jpg', null, 1],
            ],
            $this->outboxRows(),
        );
    }

    #[Test]
    public function it_pushes_nothing_to_the_queue_during_import(): void
    {
        Queue::fake();
        $this->bindRecords($this->record('1', 'Cowboy Bebop'), $this->record('2', 'Trigun'));

        $this->import();

        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 2);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_rolls_back_outbox_rows_with_the_failed_batch(): void
    {
        config()->set('import.checkpoint_every', 1);
        $this->bindRecords($this->record('1', 'Cowboy Bebop'), $this->record('2', 'Trigun'));
        $this->app->instance(CandidateSinkContract::class, new FailingOnSecondFlushSink(app(CatalogCandidateSink::class)));

        try {
            $this->import();
            self::fail('The second batch must fail after the sink has planned images.');
        } catch (RuntimeException $exception) {
            self::assertSame('Failure after image planning.', $exception->getMessage());
        }

        self::assertSame([$this->keyOf('Cowboy Bebop')], array_column($this->outboxRows(), 0));
        $this->assertDatabaseCount(Title::TABLE_NAME, 1);
        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 1]);
    }

    #[Test]
    public function it_writes_outbox_rows_once_after_resume(): void
    {
        config()->set('import.checkpoint_every', 1);
        $this->bindRecords($this->record('1', 'Cowboy Bebop'), $this->record('2', 'Trigun'));
        $this->app->instance(CandidateSinkContract::class, new FailingOnSecondFlushSink(app(CatalogCandidateSink::class)));

        try {
            $this->import();
            self::fail('The second batch must fail.');
        } catch (RuntimeException) {
        }

        $this->app->forgetInstance(CandidateSinkContract::class);
        $this->app->bind(CandidateSinkContract::class, CatalogCandidateSink::class);
        $result = app(ImportTitlesFromProvider::class)->execute(ProviderSource::Mock, ProviderDataSource::Api, resume: true);

        self::assertSame(ImportRunMode::Resumed, $result->mode);
        self::assertSame(
            [
                [$this->keyOf('Cowboy Bebop'), self::POSTER_URL, null, 1],
                [$this->keyOf('Trigun'), self::POSTER_URL, null, 1],
            ],
            $this->outboxRows(),
        );
    }

    #[Test]
    public function it_does_not_enqueue_titles_whose_hashes_match(): void
    {
        $this->bindRecords($this->record('1', 'Cowboy Bebop', bannerUrl: self::BANNER_URL));
        $this->import();
        $this->storeHashes(self::POSTER_URL, self::BANNER_URL);
        DB::table(TitleImageIngestion::TABLE_NAME)->delete();

        $this->import();

        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 0);
    }

    #[Test]
    public function it_replaces_a_pending_row_with_the_latest_urls(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 10:00:00'));
        $this->bindRecords($this->record('1', 'Cowboy Bebop'));
        $this->import();
        $this->travel(5)->minutes();
        $this->bindRecords($this->record('1', 'Cowboy Bebop', posterUrl: self::NEW_POSTER_URL));

        $this->import();

        self::assertSame([[$this->keyOf('Cowboy Bebop'), self::NEW_POSTER_URL, null, 2]], $this->outboxRows());
        $row = TitleImageIngestion::query()->firstOrFail();
        self::assertTrue($row->created_at->equalTo(Carbon::parse('2026-11-17 10:00:00')));
        self::assertTrue($row->updated_at->equalTo(Carbon::parse('2026-11-17 10:05:00')));
    }

    #[Test]
    public function it_discards_a_pending_row_when_the_provider_returns_an_already_ingested_url(): void
    {
        $this->bindRecords($this->record('1', 'Cowboy Bebop', posterUrl: self::NEW_POSTER_URL));
        $this->import();
        $this->storeHashes(self::POSTER_URL, null);
        $this->bindRecords($this->record('1', 'Cowboy Bebop', posterUrl: self::POSTER_URL));

        $this->import();

        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 0);
    }

    #[Test]
    public function it_does_not_enqueue_titles_excluded_by_a_key_collision(): void
    {
        $this->bindRecords(
            $this->record('1', 'Cowboy Bebop'),
            $this->record('2', 'Cowboy  Bebop!'),
            $this->record('3', 'Trigun'),
        );

        $this->import();

        self::assertSame([$this->keyOf('Trigun')], array_column($this->outboxRows(), 0));
    }

    #[Test]
    public function it_does_not_enqueue_a_title_without_urls_and_keeps_it_flagged(): void
    {
        $this->bindRecords($this->record('1', 'Cowboy Bebop', posterUrl: null));

        $this->import();

        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 0);
        $this->assertDatabaseHas(Title::TABLE_NAME, [Title::FIELD_TITLE_EN => 'Cowboy Bebop', Title::FIELD_IS_INCOMPLETE => true]);
    }

    #[Test]
    public function it_keeps_stored_image_keys_when_the_provider_drops_the_url(): void
    {
        $this->bindRecords($this->record('1', 'Cowboy Bebop'));
        $this->import();
        DB::table(TitleImageIngestion::TABLE_NAME)->delete();
        $this->storeHashes(self::POSTER_URL, null);
        DB::table(Title::TABLE_NAME)->update([
            Title::FIELD_POSTER_KEY => 'titles/posters/uuid/full.webp',
            Title::FIELD_POSTER_THUMB_KEY => 'titles/posters/uuid/thumb.webp',
        ]);
        $this->bindRecords($this->record('1', 'Cowboy Bebop', posterUrl: null));

        $this->import();

        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 0);
        $this->assertDatabaseHas(Title::TABLE_NAME, [
            Title::FIELD_POSTER_KEY => 'titles/posters/uuid/full.webp',
            Title::FIELD_POSTER_THUMB_KEY => 'titles/posters/uuid/thumb.webp',
            Title::FIELD_POSTER_SOURCE_HASH => hash('sha256', self::POSTER_URL),
        ]);
    }

    #[Test]
    public function it_enqueues_the_real_fixture(): void
    {
        $sink = new UrlCountingSink(app(CatalogCandidateSink::class));
        $this->app->instance(CandidateSinkContract::class, $sink);
        $directory = sys_get_temp_dir().'/image-outbox-fixture-'.bin2hex(random_bytes(8));
        CommittedShikimoriDump::configure(CommittedShikimoriDump::create(
            $directory,
            (string) file_get_contents(base_path('static/Fixtures/Shikimori/animes.ndjson')),
        ));

        try {
            $this->import(ProviderSource::Shikimori, ProviderDataSource::Dump);
        } finally {
            File::deleteDirectory($directory);
        }

        $this->assertDatabaseCount(Title::TABLE_NAME, 266);
        self::assertGreaterThan(0, $sink->withAnyUrl);
        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, $sink->withAnyUrl);
        self::assertSame(0, DB::table(TitleImageIngestion::TABLE_NAME)->whereNull(TitleImageIngestion::FIELD_POSTER_URL)->whereNull(TitleImageIngestion::FIELD_BANNER_URL)->count());
    }


    protected function setUp(): void
    {
        parent::setUp();

        $this->createImageOutboxImportSchema();
        $this->seed(AttributeDefinitionSeeder::class);
    }


    private function import(ProviderSource $provider = ProviderSource::Mock, ProviderDataSource $source = ProviderDataSource::Api): void
    {
        app(ImportTitlesFromProvider::class)->execute($provider, $source);
    }

    private function outboxRows(): array
    {
        return DB::table(TitleImageIngestion::TABLE_NAME)
            ->orderBy(TitleImageIngestion::FIELD_ID)
            ->get()
            ->map(static fn (object $row): array => [
                $row->canonical_key,
                $row->poster_url,
                $row->banner_url,
                (int) $row->revision,
            ])
            ->all();
    }

    private function keyOf(string $titleEn): string
    {
        return (string) Title::query()->where(Title::FIELD_TITLE_EN, $titleEn)->value(Title::FIELD_CANONICAL_KEY);
    }

    private function storeHashes(?string $posterUrl, ?string $bannerUrl): void
    {
        DB::table(Title::TABLE_NAME)->update([
            Title::FIELD_POSTER_SOURCE_HASH => $posterUrl === null ? null : hash('sha256', $posterUrl),
            Title::FIELD_BANNER_SOURCE_HASH => $bannerUrl === null ? null : hash('sha256', $bannerUrl),
        ]);
    }

    private function bindRecords(ProviderTitleRecord ...$records): void
    {
        $this->app->instance(ProviderClientFactoryContract::class, new SingleProviderClientFactory(new MockProviderClient($records)));
    }

    private function record(
        string  $externalId,
        string  $titleEn,
        ?string $posterUrl = self::POSTER_URL,
        ?string $bannerUrl = null,
    ): ProviderTitleRecord {
        return new ProviderTitleRecord(ProviderSource::Mock, $externalId, [
            'title' => LocalizedText::create(null, $titleEn),
            'description' => LocalizedText::create('Описание', null),
            'year' => 1998,
            'durationMinutes' => 24,
            'rating' => 8.5,
            'posterUrl' => $posterUrl,
            'bannerUrl' => $bannerUrl,
            'type' => TitleContentType::ANIME->value,
            'status' => TitleStatus::RELEASED->value,
            'format' => TitleFormat::TV,
            'ratingCount' => 100,
            'attributes' => [
                new ProviderAttribute('genres', LocalizedText::create('Экшен', 'Action')),
            ],
        ]);
    }
}

final class FailingOnSecondFlushSink implements CandidateSinkContract
{
    private int $flushes = 0;


    public function __construct(private readonly CandidateSinkContract $sink) {}

    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        $this->sink->consume($candidate, $decision);
    }

    public function flush(): void
    {
        $this->sink->flush();

        if (++$this->flushes === 2) {
            throw new RuntimeException('Failure after image planning.');
        }
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

final class UrlCountingSink implements CandidateSinkContract
{
    public int $withAnyUrl = 0;


    public function __construct(private readonly CandidateSinkContract $sink) {}

    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        if ($candidate->posterUrl !== null || $candidate->bannerUrl !== null) {
            $this->withAnyUrl++;
        }

        $this->sink->consume($candidate, $decision);
    }

    public function flush(): void
    {
        $this->sink->flush();
    }

    public function finish(): void
    {
        $this->sink->finish();
    }

    public function abort(): void
    {
        $this->sink->abort();
    }
}
