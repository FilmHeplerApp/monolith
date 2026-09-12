<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Bulk\Catalog;

use RuntimeException;

final class UnresolvedCatalogReferenceException extends RuntimeException
{
    public static function attributeDefinition(string $code): self
    {
        return new self(sprintf('Attribute definition "%s" was not found for bulk write.', $code));
    }

    public static function title(string $uuid): self
    {
        return new self(sprintf('Title "%s" was not found for bulk write.', $uuid));
    }
}
