<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;

final readonly class CatalogAttributeDefinitionPackData
{
    public function __construct(
        public string             $code,
        public LocalizedText      $name,
        public TitleContentType   $contentType,
        public AttributeValueType $valueType,
        public bool               $isFilterable,
        public bool               $isRequired,
        public int                $order,
    ) {
    }
}
