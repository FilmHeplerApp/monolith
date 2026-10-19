<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Bulk\Catalog;

use RuntimeException;

final class IncompleteTitleAttributeSetException extends RuntimeException
{
    public static function contentTypeChange(string $canonicalKey): self
    {
        return new self(sprintf(
            'Changing content type of title "%s" requires an explicitly complete attribute set.',
            $canonicalKey,
        ));
    }
}
