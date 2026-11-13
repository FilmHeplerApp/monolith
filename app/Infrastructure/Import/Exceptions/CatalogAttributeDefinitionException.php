<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Exceptions;

use RuntimeException;

final class CatalogAttributeDefinitionException extends RuntimeException
{
    public static function missing(string $contentType, string $code): self
    {
        return new self("Missing catalog attribute definition [$contentType:$code]. Seed the attribute definitions before importing.");
    }

    public static function incompatible(string $contentType, string $code, string $expected, string $actual): self
    {
        return new self("Catalog attribute definition [$contentType:$code] has type [$actual], expected [$expected].");
    }
}
