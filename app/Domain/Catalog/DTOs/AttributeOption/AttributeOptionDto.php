<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs\AttributeOption;

use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use DateTimeImmutable;

final readonly class AttributeOptionDto
{
    public function __construct(
        public ?int               $id,
        public int                $attributeId,
        public LocalizedText      $value,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }
}
