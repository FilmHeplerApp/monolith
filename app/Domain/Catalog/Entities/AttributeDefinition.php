<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entities;

use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use DateTimeImmutable;

final readonly class AttributeDefinition
{
    /**
     * @param list<AttributeOption> $options
     */
    public function __construct(
        public ?int               $id,
        public TitleContentType   $contentTypeCode,
        public string             $code,
        public LocalizedText      $name,
        public AttributeValueType $type,
        public bool               $isFilterable,
        public bool               $isRequired,
        public int                $order,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public array              $options = [],
    ) {
    }
}
