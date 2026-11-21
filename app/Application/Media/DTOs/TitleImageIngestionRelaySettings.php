<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

final readonly class TitleImageIngestionRelaySettings
{
    public function __construct(
        public int $publishLimit,
        public int $chunk,
        public int $maxQueueSize,
        public int $staleAfterMinutes,
    ) {}
}
