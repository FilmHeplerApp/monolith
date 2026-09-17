<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;

final readonly class AttributeDefinitionData
{
    public function __construct(
        public string             $code,
        public LocalizedText      $name,
        public TitleContentType   $contentTypeCode,
        public AttributeValueType $type,
        public bool               $isFilterable,
        public bool               $isRequired,
        public int                $order,
    ) {
    }
}
