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

    public static function notAList(): self
    {
        return new self('Embedding must be a list of numbers.');
    }

    public static function invalidValue(int $index): self
    {
        return new self(sprintf('Embedding element [%d] must be a finite float32 number.', $index));
    }
}
