<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Queue;

use App\Application\Media\Contracts\TitleImageIngestionQueueContract;
use App\Application\Media\DTOs\PendingTitleImageIngestion;
use App\Application\Media\Jobs\IngestTitleImagesJob;
use App\Infrastructure\Media\Config\ImageConfig;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

final readonly class LaravelTitleImageIngestionQueue implements TitleImageIngestionQueueContract
{
    public function __construct(
        private QueueFactory $queues,
        private Dispatcher   $dispatcher,
    ) {}

    public function size(): int
    {
        return $this->queues->connection()->size(ImageConfig::queue());
    }

    public function push(PendingTitleImageIngestion $ingestion): void
    {
        $this->dispatcher->dispatch(new IngestTitleImagesJob(
            $ingestion->canonicalKey,
            $ingestion->posterUrl,
            $ingestion->bannerUrl,
        ));
    }
}
