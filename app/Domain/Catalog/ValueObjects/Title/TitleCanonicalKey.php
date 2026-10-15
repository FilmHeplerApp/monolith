<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\Title;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class TitleCanonicalKey
{
    public static function createFromString(
        string  $value,
        ?string $natural = null,
    ): self {
        $normalized = trim($value);

        if ($normalized === '') {
            throw InvalidCatalogValueException::emptyCanonicalKey();
        }

        return new self($normalized, $natural);
    }

    public static function createFromNatural(string $natural): self
    {
        return self::createFromString(sha1($natural), $natural);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getNatural(): ?string
    {
        return $this->natural;
    }

    public function __toString(): string
    {
        return $this->value;
    }


    private function __construct(
        private string  $value,
        private ?string $natural,
    ) {}
}
