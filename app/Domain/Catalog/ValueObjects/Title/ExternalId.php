<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\Title;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class ExternalId
{
    private function __construct(
        private string $value,
    ) {
    }


    public static function createFromString(string $value): self
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw InvalidCatalogValueException::emptyExternalId();
        }

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $normalized)) {
            throw InvalidCatalogValueException::invalidExternalId($normalized);
        }

        return new self(strtolower($normalized));
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
