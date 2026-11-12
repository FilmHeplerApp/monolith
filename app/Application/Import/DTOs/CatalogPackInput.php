<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

final readonly class CatalogPackInput
{
    /**
     * @param list<CatalogTitlePackData> $titles
     * @param list<CatalogAttributeDefinitionPackData> $attributeDefinitions
     */
    public function __construct(
        public array $titles,
        public array $attributeDefinitions,
    ) {}
}
