<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Exceptions;

use RuntimeException;

final class ImportConfigException extends RuntimeException
{
    public static function emptyAllowedTypes(): self
    {
        return new self('Config import.filter.allowed_types must contain at least one content type.');
    }

    public static function unknownAllowedType(mixed $type): self
    {
        $value = is_scalar($type) ? (string) $type : get_debug_type($type);

        return new self("Unknown content type [$value] in config import.filter.allowed_types.");
    }
}
