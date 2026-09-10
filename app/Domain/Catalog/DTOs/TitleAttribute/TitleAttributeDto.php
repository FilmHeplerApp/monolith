<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs\TitleAttribute;

use App\Domain\Catalog\ValueObjects\TitleAttribute\AttributeValue;
use DateTimeImmutable;

final readonly class TitleAttributeDto
{
    public function __construct(
        public ?int $id,
        public int $titleId,
        public int $attributeId,
        public AttributeValue $value,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }
}
