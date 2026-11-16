<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use InvalidArgumentException;

final class InvalidEmbeddingException extends InvalidArgumentException
{
    public static function invalidDimension(int $expected, int $actual): self
    {
        return new self(sprintf('Embedding must have %d dimensions, got %d.', $expected, $actual));
    }

    public static function invalidValue(int|string $index): self
    {
        return new self(sprintf('Embedding element [%s] must be a finite float32 number.', $index));
    }
}
