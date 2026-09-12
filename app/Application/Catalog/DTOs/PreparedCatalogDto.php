<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

final readonly class PreparedCatalogDto
{
    /**
     * @param list<TitleData> $titles
     * @param list<AttributeDefinitionData> $attributeDefinitions
     * @param list<AttributeOptionData> $attributeOptions
     * @param list<TitleAttributeData> $titleAttributes
     */
    public function __construct(
        public array $titles = [],
        public array $attributeDefinitions = [],
        public array $attributeOptions = [],
        public array $titleAttributes = [],
    ) {
    }
}
