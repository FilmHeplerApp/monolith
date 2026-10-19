<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

final readonly class TitleImageData
{
    public function __construct(
        public ?string $posterKey,
        public ?string $posterThumbKey,
        public ?string $bannerKey,
        public ?string $posterSourceHash,
        public ?string $bannerSourceHash,
    ) {
    }
}
