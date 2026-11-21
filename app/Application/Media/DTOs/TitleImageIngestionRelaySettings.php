<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

use InvalidArgumentException;

final readonly class TitleImageIngestionRelaySettings
{
    public function __construct(
        public int $publishLimit,
        public int $chunk,
        public int $maxQueueSize,
        public int $staleAfterMinutes,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("Title image relay setting [$name] must be positive.");
            }
        }
    }
}
