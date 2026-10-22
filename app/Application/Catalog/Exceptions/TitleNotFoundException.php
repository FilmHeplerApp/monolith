<?php

declare(strict_types=1);

namespace App\Application\Catalog\Exceptions;

use RuntimeException;

final class TitleNotFoundException extends RuntimeException
{
    public static function forCanonicalKey(string $canonicalKey): self
    {
        return new self(sprintf('Title was not found for canonical key "%s".', $canonicalKey));
    }
}
