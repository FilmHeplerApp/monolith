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

    public static function blankNickname(): self
    {
        return new self('Nickname must not be empty.');
    }

    public static function blankPasswordHash(): self
    {
        return new self('Password hash must not be empty.');
    }

    public static function blankSecretPhraseHash(): self
    {
        return new self('Secret phrase hash must not be empty.');
    }

    public static function invalidBirthday(): self
    {
        return new self('Birthday must be a valid date.');
    }

    public static function birthdayInFuture(): self
    {
        return new self('Birthday must not be in the future.');
    }

    public static function birthdayTooEarly(int $minYear): self
    {
        return new self(sprintf('Birthday must not be earlier than %d.', $minYear));
    }

    public static function invalidTimezone(string $value): self
    {
        return new self(sprintf('Timezone "%s" is invalid.', $value));
    }

    public static function nonExistentUser(): self
    {
        return new self('User must be persisted before this operation.');
    }
}
