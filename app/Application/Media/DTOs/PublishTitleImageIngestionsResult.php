<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

use Throwable;

final readonly class PublishTitleImageIngestionsResult
{
    public function __construct(
        public int                        $published,
        public int                        $superseded,
        public TitleImageIngestionBacklog $backlog,
        public int                        $oldestAgeSeconds,
        public bool                       $queueFull = false,
        public ?Throwable                 $failure = null,
    ) {}

    public function failed(): bool
    {
        return $this->failure !== null;
    }
}
