<?php

declare(strict_types=1);

namespace App\Application\Media\Jobs;

use App\Application\Media\UseCases\IngestTitleImageHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessTitleImageJob implements ShouldQueue
{
    use Queueable;


    public int $tries = 1;
    public int $timeout = 180;


    public function __construct(public int $requestId, public int $version)
    {
        $this->onQueue(config('images.queue', 'images'));
    }

    public function handle(IngestTitleImageHandler $handler): void
    {
        $handler->handle($this->requestId, $this->version);
    }
}
