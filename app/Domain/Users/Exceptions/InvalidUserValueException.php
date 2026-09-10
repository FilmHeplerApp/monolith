<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use InvalidArgumentException;

final class InvalidUserValueException extends InvalidArgumentException
{
    public static function blankEmail(): self
    {
        return new self('Email must not be empty.');
    }

    public static function invalidEmail(string $value): self
    {
        return new self(sprintf('Email "%s" is invalid.', $value));
    }

    public static function blankName(): self
    {
        return new self('User name must not be empty.');
    }
}
