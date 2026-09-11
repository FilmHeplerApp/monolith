<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\AttributeDefinition;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class AttributeValueType
{
    private function __construct(
        private string $value,
    ) {
    }


    public static function createFromString(string $value): self
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw InvalidCatalogValueException::emptyAttributeCode();
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $normalized)) {
            throw InvalidCatalogValueException::invalidAttributeCode($normalized);
        }

        if (!AttributeValueType::tryFrom($value)) {
            throw InvalidCatalogValueException::invalidAttributeCode($normalized);
        }

        return new self($normalized);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
