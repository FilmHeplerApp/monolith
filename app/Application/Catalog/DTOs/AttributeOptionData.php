<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;

final readonly class AttributeOptionData
{
    public function __construct(
        public string           $attributeCode,
        public TitleContentType $contentTypeCode,
        public LocalizedText    $value,
    ) {}
}
