<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Application\Media\Contracts\TitleImageIngestionOutboxContract;
use App\Application\Media\Contracts\TitleImageIngestionQueueContract;
use App\Application\Media\DTOs\PendingTitleImageIngestion;
use App\Application\Media\DTOs\TitleImageIngestionBacklog;
use App\Application\Media\DTOs\TitleImageIngestionRequest;
use App\Application\Media\Jobs\IngestTitleImagesJob;
use App\Infrastructure\Persistence\Eloquent\Media\EloquentTitleImageIngestionOutbox;
use App\Infrastructure\Persistence\Eloquent\Models\Media\TitleImageIngestion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesImageOutboxImportSchema;
use Tests\TestCase;

final class PublishTitleImageIngestionsCommandTest extends TestCase
{
    use CreatesImageOutboxImportSchema;


    #[Test]
    public function it_publishes_pending_rows_to_the_images_queue(): void
    {
        Queue::fake();
        $this->enqueue(
            new TitleImageIngestionRequest('a|anime', 'https://cdn.example/a.jpg', 'https://cdn.example/a-banner.jpg'),
            new TitleImageIngestionRequest('b|anime', null, 'https://cdn.example/b-banner.jpg'),
        );

        $this->artisan('media:publish-image-ingestions')
            ->expectsTable(['Published', 'Superseded', 'Backlog', 'Oldest age'], [[2, 0, 0, '0s']])
            ->assertSuccessful();

        Queue::assertPushedOn('images', IngestTitleImagesJob::class, static fn (IngestTitleImagesJob $job): bool => $job->canonicalKey === 'a|anime'
            && $job->posterUrl === 'https://cdn.example/a.jpg'
            && $job->bannerUrl === 'https://cdn.example/a-banner.jpg');
        Queue::assertPushedOn('images', IngestTitleImagesJob::class, static fn (IngestTitleImagesJob $job): bool => $job->canonicalKey === 'b|anime'
            && $job->posterUrl === null
            && $job->bannerUrl === 'https://cdn.example/b-banner.jpg');
        Queue::assertPushed(IngestTitleImagesJob::class, 2);
        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 0);
    }

    #[Test]
    public function it_keeps_rows_and_fails_when_the_queue_is_unavailable(): void
    {
        Log::spy();
        $this->enqueue(new TitleImageIngestionRequest('a|anime', 'https://cdn.example/a.jpg', null));
        $this->app->instance(TitleImageIngestionQueueContract::class, new class implements TitleImageIngestionQueueContract
        {
            public function size(): int
            {
                return 0;
            }

            public function push(PendingTitleImageIngestion $ingestion): void
            {
                throw new RuntimeException('Connection refused [tcp://redis:6379].');
            }
        });

        $this->artisan('media:publish-image-ingestions')
            ->expectsOutputToContain('Connection refused [tcp://redis:6379].')
            ->assertFailed();

        $this->assertDatabaseHas(TitleImageIngestion::TABLE_NAME, [TitleImageIngestion::FIELD_CANONICAL_KEY => 'a|anime', TitleImageIngestion::FIELD_REVISION => 1]);
        Log::shouldHaveReceived('error')->once()->withArgs(
            static fn (string $message, array $context): bool => $message === 'Title image ingestion was not published.'
                && $context['canonical_key'] === 'a|anime',
        );
    }

    #[Test]
    public function it_keeps_a_row_updated_between_fetch_and_acknowledge(): void
    {
        Queue::fake();
        $this->enqueue(new TitleImageIngestionRequest('a|anime', 'https://cdn.example/a.jpg', null));
        $this->app->instance(TitleImageIngestionOutboxContract::class, new ConcurrentImportOutbox(new EloquentTitleImageIngestionOutbox));

        $this->artisan('media:publish-image-ingestions')
            ->expectsTable(['Published', 'Superseded', 'Backlog', 'Oldest age'], [[0, 1, 1, '0s']])
            ->assertSuccessful();

        Queue::assertPushed(IngestTitleImagesJob::class, 1);
        $this->assertDatabaseHas(TitleImageIngestion::TABLE_NAME, [
            TitleImageIngestion::FIELD_CANONICAL_KEY => 'a|anime',
            TitleImageIngestion::FIELD_POSTER_URL => 'https://cdn.example/a-new.jpg',
            TitleImageIngestion::FIELD_REVISION => 2,
        ]);
    }

    #[Test]
    public function it_rejects_a_non_positive_limit(): void
    {
        Queue::fake();
        $this->enqueue(new TitleImageIngestionRequest('a|anime', 'https://cdn.example/a.jpg', null));

        foreach (['0', '-1', 'abc'] as $limit) {
            $this->artisan('media:publish-image-ingestions', ['--limit' => $limit])
                ->expectsOutput('Limit must be a positive integer.')
                ->assertFailed();
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 1);
    }

    #[Test]
    public function it_is_registered_in_the_schedule(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        self::assertMatchesRegularExpression('/\* \* \* \* \*\s+php artisan media:publish-image-ingestions/', $output);
    }


    protected function setUp(): void
    {
        parent::setUp();

        $this->createImageOutboxImportSchema();
    }


    private function enqueue(TitleImageIngestionRequest ...$requests): void
    {
        foreach ($requests as $request) {
            $this->insertTitle($request->canonicalKey);
        }

        app(EloquentTitleImageIngestionOutbox::class)->enqueue($requests);
    }
}

final readonly class ConcurrentImportOutbox implements TitleImageIngestionOutboxContract
{
    public function __construct(private TitleImageIngestionOutboxContract $outbox) {}

    public function enqueue(array $requests): void
    {
        $this->outbox->enqueue($requests);
    }

    public function discard(array $canonicalKeys): void
    {
        $this->outbox->discard($canonicalKeys);
    }

    public function pending(int $afterId, int $limit): array
    {
        return $this->outbox->pending($afterId, $limit);
    }

    public function acknowledge(int $id, int $revision): bool
    {
        $key = (string) DB::table(TitleImageIngestion::TABLE_NAME)->where(TitleImageIngestion::FIELD_ID, $id)->value(TitleImageIngestion::FIELD_CANONICAL_KEY);
        $this->outbox->enqueue([new TitleImageIngestionRequest($key, 'https://cdn.example/a-new.jpg', null)]);

        return $this->outbox->acknowledge($id, $revision);
    }

    public function backlog(): TitleImageIngestionBacklog
    {
        return $this->outbox->backlog();
    }
}
