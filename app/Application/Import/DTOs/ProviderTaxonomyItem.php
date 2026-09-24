<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

final readonly class ProviderTaxonomyItem
{
    public function __construct(
        public string  $kind,
        public ?string $nameRu,
        public ?string $nameEn,
    ) {
    }
}
