<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Media\UseCases;

use App\Application\Media\DTOs\TitleImageIngestionRelaySettings;
use App\Application\Media\UseCases\PublishTitleImageIngestionsHandler;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InMemoryTitleImageIngestionOutbox;
use Tests\Support\RecordingTitleImageIngestionQueue;
use Tests\TestCase;

final class PublishTitleImageIngestionsHandlerTest extends TestCase
{
    private InMemoryTitleImageIngestionOutbox $outbox;


    #[Test]
    public function it_pushes_each_pending_row_and_acknowledges_its_revision(): void
    {
        $this->outbox->add('a|anime', 'https://cdn.example/a.jpg', null);
        $this->outbox->add('b|anime', null, 'https://cdn.example/b.jpg');
        $queue = new RecordingTitleImageIngestionQueue;

        $result = $this->handler($queue)->handle();

        self::assertSame(['a|anime', 'b|anime'], array_map(static fn ($row): string => $row->canonicalKey, $queue->pushed));
        self::assertSame([null, 'https://cdn.example/b.jpg'], [$queue->pushed[0]->bannerUrl, $queue->pushed[1]->bannerUrl]);
        self::assertSame(2, $result->published);
        self::assertSame(0, $result->superseded);
        self::assertSame(0, $result->backlog->count);
        self::assertFalse($result->failed());
        self::assertSame([], $this->outbox->rows);
    }

    #[Test]
    public function it_pushes_before_acknowledging(): void
    {
        $this->outbox->add('a|anime');
        $this->outbox->add('b|anime');

        $this->handler(new RecordingTitleImageIngestionQueue(outbox: $this->outbox))->handle();

        self::assertSame(['push:1', 'acknowledge:1', 'push:2', 'acknowledge:2'], $this->outbox->calls);
    }

    #[Test]
    public function it_stops_on_the_first_push_failure_and_keeps_the_remaining_rows(): void
    {
        Log::spy();
        $this->outbox->add('a|anime');
        $this->outbox->add('b|anime');
        $this->outbox->add('c|anime');
        $queue = new RecordingTitleImageIngestionQueue(failOnPush: 2);

        $result = $this->handler($queue)->handle();

        self::assertTrue($result->failed());
        self::assertSame('Redis is unavailable.', $result->failure->getMessage());
        self::assertSame(1, $result->published);
        self::assertCount(1, $queue->pushed);
        self::assertSame([2, 3], array_keys($this->outbox->rows));
        Log::shouldHaveReceived('error')->once()->withArgs(
            static fn (string $message, array $context): bool => $context['canonical_key'] === 'b|anime'
                && $context['outbox_id'] === 2
                && $context['revision'] === 1
                && $context['published_before_failure'] === 1,
        );
    }

    #[Test]
    public function it_counts_a_superseded_revision_and_keeps_the_row(): void
    {
        $this->outbox->add('a|anime');
        $this->outbox->beforeAcknowledge = static fn (InMemoryTitleImageIngestionOutbox $outbox, int $id) => $outbox->bumpRevision($id);

        $result = $this->handler(new RecordingTitleImageIngestionQueue)->handle();

        self::assertSame(0, $result->published);
        self::assertSame(1, $result->superseded);
        self::assertSame(2, $this->outbox->rows[1]->revision);
        self::assertSame(1, $result->backlog->count);
    }

    #[Test]
    public function it_does_not_reread_a_superseded_row_in_the_same_run(): void
    {
        $this->outbox->add('a|anime');
        $this->outbox->add('b|anime');
        $this->outbox->beforeAcknowledge = static function (InMemoryTitleImageIngestionOutbox $outbox, int $id): void {
            if ($id === 1) {
                $outbox->bumpRevision($id);
            }
        };
        $queue = new RecordingTitleImageIngestionQueue;

        $result = $this->handler($queue, chunk: 1)->handle();

        self::assertSame([1, 2], array_map(static fn ($row): int => $row->id, $queue->pushed));
        self::assertSame([[0, 1], [1, 1], [2, 1]], $this->outbox->pendingQueries);
        self::assertSame(1, $result->published);
        self::assertSame(1, $result->superseded);
    }

    #[Test]
    public function it_respects_the_limit_and_the_chunk_size(): void
    {
        foreach (range(1, 10) as $index) {
            $this->outbox->add("title-$index|anime");
        }
        $queue = new RecordingTitleImageIngestionQueue;

        $result = $this->handler($queue, publishLimit: 7, chunk: 3)->handle(limit: 5);

        self::assertSame(5, $result->published);
        self::assertSame([[0, 3], [3, 2]], $this->outbox->pendingQueries);
        self::assertSame(5, $result->backlog->count);

        $this->outbox->pendingQueries = [];
        $second = $this->handler($queue, publishLimit: 4, chunk: 3)->handle(limit: 50);

        self::assertSame(4, $second->published);
        self::assertSame([[0, 3], [8, 1]], $this->outbox->pendingQueries);
    }

    #[Test]
    public function it_publishes_nothing_when_the_queue_is_full(): void
    {
        Log::spy();
        $this->outbox->add('a|anime');
        $queue = new RecordingTitleImageIngestionQueue(queueSize: 10);

        $result = $this->handler($queue, maxQueueSize: 10)->handle();

        self::assertTrue($result->queueFull);
        self::assertSame(0, $result->published);
        self::assertSame([], $queue->pushed);
        self::assertSame([], $this->outbox->pendingQueries);
        self::assertSame(1, $result->backlog->count);
        Log::shouldHaveReceived('info')->once()->withArgs(
            static fn (string $message, array $context): bool => $context === ['queue_size' => 10, 'max_queue_size' => 10, 'backlog' => 1],
        );
    }

    #[Test]
    public function it_publishes_only_the_free_room_of_the_queue(): void
    {
        foreach (range(1, 5) as $index) {
            $this->outbox->add("title-$index|anime");
        }
        $queue = new RecordingTitleImageIngestionQueue(queueSize: 8);

        $result = $this->handler($queue, maxQueueSize: 10)->handle();

        self::assertSame(2, $result->published);
        self::assertSame(3, $result->backlog->count);
    }

    #[Test]
    public function it_warns_when_the_backlog_is_stale(): void
    {
        Log::spy();
        $this->travelTo('2026-11-20 12:00:00');
        $this->outbox->add('a|anime');
        $this->outbox->oldestCreatedAt = new \DateTimeImmutable('2026-11-20 10:00:00');

        $result = $this->handler(new RecordingTitleImageIngestionQueue(queueSize: 1), maxQueueSize: 1, staleAfterMinutes: 60)->handle();

        self::assertSame(7200, $result->oldestAgeSeconds);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $context === ['oldest_age_seconds' => 7200, 'stale_after_minutes' => 60, 'backlog_count' => 1],
        );
    }


    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = new InMemoryTitleImageIngestionOutbox;
    }


    private function handler(
        RecordingTitleImageIngestionQueue $queue,
        int                               $publishLimit = 500,
        int                               $chunk = 100,
        int                               $maxQueueSize = 1000,
        int                               $staleAfterMinutes = 60,
    ): PublishTitleImageIngestionsHandler {
        return new PublishTitleImageIngestionsHandler(
            $this->outbox,
            $queue,
            new TitleImageIngestionRelaySettings($publishLimit, $chunk, $maxQueueSize, $staleAfterMinutes),
        );
    }
}
