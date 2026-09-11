<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use InvalidArgumentException;

final class InvalidCatalogValueException extends InvalidArgumentException
{
    public static function emptyExternalId(): self
    {
        return new self('External id must not be empty.');
    }

    public static function invalidExternalId(string $value): self
    {
        return new self(sprintf('External id "%s" is not a valid UUID.', $value));
    }

    public static function emptyAttributeCode(): self
    {
        return new self('Attribute code must not be empty.');
    }

    public static function invalidAttributeCode(string $value): self
    {
        return new self(sprintf('Attribute code "%s" has an invalid format.', $value));
    }

    public static function nonPositiveDuration(int $minutes): self
    {
        return new self(sprintf('Duration must be positive, got %d.', $minutes));
    }

    public static function ratingOutOfRange(float $average): self
    {
        return new self(sprintf('Rating average must be between 0 and 10, got %s.', $average));
    }

    public static function negativeRatingCount(int $count): self
    {
        return new self(sprintf('Rating count must be >= 0, got %d.', $count));
    }

    public static function invalidEmbeddingDimension(int $expected, int $actual): self
    {
        return new self(sprintf('Embedding must have %d dimensions, got %d.', $expected, $actual));
    }

    public static function emptyAttributeValue(): self
    {
        return new self('Attribute value must contain exactly one typed value.');
    }

    public static function multipleAttributeValues(): self
    {
        return new self('Attribute value must contain exactly one typed value.');
    }
}
