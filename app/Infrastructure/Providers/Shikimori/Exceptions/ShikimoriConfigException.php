<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Exceptions;

use RuntimeException;

final class ShikimoriConfigException extends RuntimeException
{
    public static function invalidPageSize(int $pageSize, int $maxPageSize): self
    {
        return new self("Shikimori page size must not exceed $maxPageSize, got $pageSize.");
    }
}
