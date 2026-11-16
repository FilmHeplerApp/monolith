<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use RuntimeException;

final class UserConcurrentModificationException extends RuntimeException
{
    public static function byId(int $id): self
    {
        return new self(sprintf('User %d was changed by another process; reload it and retry.', $id));
    }
}
