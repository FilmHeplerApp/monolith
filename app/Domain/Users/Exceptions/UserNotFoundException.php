<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use RuntimeException;

final class UserNotFoundException extends RuntimeException
{
    public static function byId(int $id): self
    {
        return new self(sprintf('User %d was not found.', $id));
    }
}
