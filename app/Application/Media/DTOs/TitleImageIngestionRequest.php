<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

use InvalidArgumentException;

final readonly class TitleImageIngestionRequest
{
    public function __construct(
        public string  $canonicalKey,
        public ?string $posterUrl,
        public ?string $bannerUrl,
    ) {
        if ($posterUrl === null && $bannerUrl === null) {
            throw new InvalidArgumentException("Title image ingestion [$canonicalKey] needs a poster or a banner URL.");
        }
    }
}
