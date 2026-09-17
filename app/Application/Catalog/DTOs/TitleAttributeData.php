<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Domain\Catalog\ValueObjects\TitleAttribute\AttributeValue;

final readonly class TitleAttributeData
{
    public function __construct(
        public TitleCanonicalKey $titleCanonicalKey,
        public string            $attributeCode,
        public AttributeValue    $value,
    ) {
    }
}
