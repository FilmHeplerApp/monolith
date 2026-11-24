<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

use App\Application\Import\DTOs\CatalogTitlePackData;

final readonly class TitleImageSource
{
    public function __construct(
        public string  $canonicalKey,
        public ?string $posterUrl,
        public ?string $bannerUrl,
    ) {}

    public static function fromPackData(CatalogTitlePackData $title): self
    {
        return new self(
            canonicalKey: $title->canonicalKey->getValue(),
            posterUrl: $title->posterUrl,
            bannerUrl: $title->bannerUrl,
        );
    }
}
