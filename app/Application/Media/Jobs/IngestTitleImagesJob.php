<?php

declare(strict_types=1);

namespace App\Application\Media\Jobs;

use App\Application\Media\UseCases\IngestTitleImagesHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestTitleImagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 180;


    public function __construct(
        public string  $canonicalKey,
        public string  $posterUrl,
        public ?string $bannerUrl,
    ) {
        $this->onQueue(config('images.queue'));
    }

    public function handle(IngestTitleImagesHandler $ingestTitleImagesHandler): void
    {
        $ingestTitleImagesHandler->handle(
            canonicalKey: $this->canonicalKey,
            posterUrl: $this->posterUrl,
            bannerUrl: $this->bannerUrl,
        );
    }
}
