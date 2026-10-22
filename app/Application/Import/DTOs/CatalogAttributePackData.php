<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;

final readonly class CatalogAttributePackData
{
    /**
     * @param list<LocalizedText> $values
     */
    public function __construct(
        public string             $code,
        public AttributeValueType $valueType,
        public array              $values,
    ) {}
}
