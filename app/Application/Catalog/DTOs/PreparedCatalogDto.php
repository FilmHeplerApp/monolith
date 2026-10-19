<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;

final readonly class PreparedCatalogDto
{
    /**
     * @param list<TitleData> $titles
     * @param list<AttributeDefinitionData> $attributeDefinitions
     * @param list<AttributeOptionData> $attributeOptions
     * @param list<TitleAttributeData> $titleAttributes
     * @param list<TitleCanonicalKey> $completeAttributeSetTitleKeys
     */
    public function __construct(
        public array $titles = [],
        public array $attributeDefinitions = [],
        public array $attributeOptions = [],
        public array $titleAttributes = [],
        public array $completeAttributeSetTitleKeys = [],
    ) {
    }
}
