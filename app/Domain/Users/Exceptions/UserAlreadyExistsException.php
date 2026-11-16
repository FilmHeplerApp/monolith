<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use RuntimeException;

final class UserAlreadyExistsException extends RuntimeException
{
    public static function emailTaken(): self
    {
        return new self('Email is already taken.');
    }

    public static function nicknameTaken(): self
    {
        return new self('Nickname is already taken.');
    }
}
