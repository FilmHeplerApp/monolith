<?php

declare(strict_types=1);

namespace App\Application\Media\Jobs;

use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\UseCases\IngestTitleImagesHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

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
        try {
            $ingestTitleImagesHandler->handle(
                canonicalKey: $this->canonicalKey,
                posterUrl: $this->posterUrl,
                bannerUrl: $this->bannerUrl,
            );
        } catch (ImageDownloadException $exception) {
            if ($exception->retryAfterSeconds !== null and $this->attempts() < $this->tries) {
                $this->release($exception->retryAfterSeconds);
                return;
            }
            throw $exception;
        }
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->canonicalKey)
                ->releaseAfter($this->timeout)
                ->expireAfter($this->timeout),
        ];
    }
}
