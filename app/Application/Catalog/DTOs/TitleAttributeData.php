<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Domain\Catalog\ValueObjects\Title\TitleUuid;
use App\Domain\Catalog\ValueObjects\TitleAttribute\AttributeValue;

final readonly class TitleAttributeData
{
    public function __construct(
        public TitleUuid      $titleUuid,
        public string         $attributeCode,
        public AttributeValue $value,
    ) {
    }
}
