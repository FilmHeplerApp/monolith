<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\Title;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class TitleCanonicalKey
{
    private function __construct(
        private string $value,
    ) {}

    public static function createFromString(string $value): self
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw InvalidCatalogValueException::emptyCanonicalKey();
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
