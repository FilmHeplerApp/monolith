<?php

declare(strict_types=1);

namespace App\Application\Media\UseCases;

use App\Application\Media\Contracts\TitleImageIngestionOutboxContract;
use App\Application\Media\Contracts\TitleImageIngestionQueueContract;
use App\Application\Media\DTOs\PublishTitleImageIngestionsResult;
use App\Application\Media\DTOs\TitleImageIngestionRelaySettings;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

readonly class PublishTitleImageIngestionsHandler
{
    public function __construct(
        private TitleImageIngestionOutboxContract $outbox,
        private TitleImageIngestionQueueContract  $queue,
        private TitleImageIngestionRelaySettings  $settings,
    ) {}

    public function handle(?int $limit = null): PublishTitleImageIngestionsResult
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Limit must be a positive integer.');
        }

        $budget = min($limit ?? $this->settings->publishLimit, $this->settings->publishLimit);
        $queueSize = $this->queue->size();
        $room = $this->settings->maxQueueSize - $queueSize;

        if ($room <= 0) {
            $result = $this->result(0, 0, queueFull: true);
            Log::info('Images queue is full, title image ingestions are not published.', [
                'queue_size' => $queueSize,
                'max_queue_size' => $this->settings->maxQueueSize,
                'backlog' => $result->backlog->count,
            ]);
            $this->warnIfStale($result);

            return $result;
        }

        $budget = min($budget, $room);
        $cursor = 0;
        $published = 0;
        $superseded = 0;

        while ($budget > 0) {
            $rows = $this->outbox->pending($cursor, min($this->settings->chunk, $budget));

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $cursor = $row->id;

                try {
                    $this->queue->push($row);
                } catch (Throwable $exception) {
                    Log::error('Title image ingestion was not published.', [
                        'canonical_key' => $row->canonicalKey,
                        'outbox_id' => $row->id,
                        'revision' => $row->revision,
                        'exception' => $exception,
                        'published_before_failure' => $published,
                    ]);

                    return $this->result($published, $superseded, failure: $exception);
                }

                if ($this->outbox->acknowledge($row->id, $row->revision)) {
                    $published++;
                } else {
                    $superseded++;
                }

                $budget--;
            }
        }

        $result = $this->result($published, $superseded);
        Log::info('Title image ingestions published.', [
            'published' => $result->published,
            'superseded' => $result->superseded,
            'backlog' => $result->backlog->count,
            'oldest_age_seconds' => $result->oldestAgeSeconds,
        ]);
        $this->warnIfStale($result);

        return $result;
    }


    private function result(
        int        $published,
        int        $superseded,
        bool       $queueFull = false,
        ?Throwable $failure = null,
    ): PublishTitleImageIngestionsResult {
        $backlog = $this->outbox->backlog();

        return new PublishTitleImageIngestionsResult(
            published: $published,
            superseded: $superseded,
            backlog: $backlog,
            oldestAgeSeconds: $backlog->oldestAgeSeconds(now()),
            queueFull: $queueFull,
            failure: $failure,
        );
    }

    private function warnIfStale(PublishTitleImageIngestionsResult $result): void
    {
        if ($result->oldestAgeSeconds <= $this->settings->staleAfterMinutes * 60) {
            return;
        }

        Log::warning('Title image ingestion backlog is stale.', [
            'oldest_age_seconds' => $result->oldestAgeSeconds,
            'stale_after_minutes' => $this->settings->staleAfterMinutes,
            'backlog_count' => $result->backlog->count,
        ]);
    }
}
