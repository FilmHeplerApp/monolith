<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

final readonly class PendingTitleImageIngestion
{
    public function __construct(
        public int     $id,
        public int     $revision,
        public string  $canonicalKey,
        public ?string $posterUrl,
        public ?string $bannerUrl,
    ) {}
}
