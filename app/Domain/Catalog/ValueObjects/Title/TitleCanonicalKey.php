<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\Title;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class TitleCanonicalKey
{
    public static function createFromString(
        string  $value,
        ?string $natural = null,
        ?int    $normalizerVersion = null,
    ): self {
        $normalized = trim($value);

        if ($normalized === '') {
            throw InvalidCatalogValueException::emptyCanonicalKey();
        }

        return new self($normalized, $natural, $normalizerVersion);
    }

    public static function createFromNatural(string $natural, int $normalizerVersion): self
    {
        return self::createFromString('v'.$normalizerVersion.':'.sha1($natural), $natural, $normalizerVersion);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getNatural(): ?string
    {
        return $this->natural;
    }

    public function getNormalizerVersion(): ?int
    {
        return $this->normalizerVersion;
    }

    public function __toString(): string
    {
        return $this->value;
    }


    private function __construct(
        private string  $value,
        private ?string $natural,
        private ?int    $normalizerVersion,
    ) {}
}
