<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Media\Contracts\TitleImageIngestionQueueContract;
use App\Application\Media\DTOs\PendingTitleImageIngestion;
use RuntimeException;

final class RecordingTitleImageIngestionQueue implements TitleImageIngestionQueueContract
{
    /** @var list<PendingTitleImageIngestion> */
    public array $pushed = [];


    public function __construct(
        public int                                          $queueSize = 0,
        private readonly ?int                               $failOnPush = null,
        private readonly ?InMemoryTitleImageIngestionOutbox $outbox = null,
        private readonly bool                               $failOnSize = false,
    ) {}

    public function size(): int
    {
        if ($this->failOnSize) {
            throw new RuntimeException('Redis is unavailable.');
        }

        return $this->queueSize;
    }

    public function push(PendingTitleImageIngestion $ingestion): void
    {
        if ($this->failOnPush !== null && count($this->pushed) + 1 === $this->failOnPush) {
            throw new RuntimeException('Redis is unavailable.');
        }

        if ($this->outbox !== null) {
            $this->outbox->calls[] = 'push:'.$ingestion->id;
        }

        $this->pushed[] = $ingestion;
    }
}
