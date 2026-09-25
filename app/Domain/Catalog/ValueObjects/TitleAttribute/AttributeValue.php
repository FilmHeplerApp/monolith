<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\TitleAttribute;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class AttributeValue
{
    /**
     * @param list<string>|null $array
     */
    private function __construct(
        private ?string $text,
        private ?array  $array,
        private ?float  $number,
        private ?bool   $boolean,
    ) {
    }


    public static function createFromText(string $value): self
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw InvalidCatalogValueException::emptyAttributeValue();
        }

        return new self($normalized, null, null, null);
    }

    /**
     * @param list<string> $values
     */
    public static function createFromArray(array $values): self
    {
        $normalized = array_values(array_filter(
            array_map(static fn (string $value): string => trim($value), $values),
            static fn (string $value): bool => $value !== '',
        ));

        if ($normalized === []) {
            throw InvalidCatalogValueException::emptyAttributeValue();
        }

        return new self(null, $normalized, null, null);
    }

    public static function createFromNumber(float $value): self
    {
        return new self(null, null, $value, null);
    }

    public static function createFromBoolean(bool $value): self
    {
        return new self(null, null, null, $value);
    }

    /**
     * @param list<string>|null $array
     */
    public static function createFromRaw(
        ?string $text = null,
        ?array  $array = null,
        ?float  $number = null,
        ?bool   $boolean = null,
    ): self {
        $filled = 0;

        if ($text !== null && trim($text) !== '') {
            $filled++;
        }

        if ($array !== null && $array !== []) {
            $filled++;
        }

        if ($number !== null) {
            $filled++;
        }

        if ($boolean !== null) {
            $filled++;
        }

        if ($filled === 0) {
            throw InvalidCatalogValueException::emptyAttributeValue();
        }

        if ($filled > 1) {
            throw InvalidCatalogValueException::multipleAttributeValues();
        }

        if ($text !== null && trim($text) !== '') {
            return self::createFromText($text);
        }

        if ($array !== null && $array !== []) {
            return self::createFromArray($array);
        }

        if ($number !== null) {
            return self::createFromNumber($number);
        }

        return self::createFromBoolean((bool) $boolean);
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * @return list<string>|null
     */
    public function getArray(): ?array
    {
        return $this->array;
    }

    public function getNumber(): ?float
    {
        return $this->number;
    }

    public function getBoolean(): ?bool
    {
        return $this->boolean;
    }

    public function getSearchableText(): string
    {
        return match (true) {
            $this->text !== null => $this->text,
            $this->array !== null => implode(' ', $this->array),
            $this->number !== null => (string) $this->number,
            $this->boolean !== null => $this->boolean ? 'true' : 'false',
            default => '',
        };
    }
}
