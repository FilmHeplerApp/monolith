<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Bulk\Catalog;

use RuntimeException;

final class UnresolvedCatalogReferenceException extends RuntimeException
{
    public static function attributeDefinition(string $contentTypeCode, string $attributeDefinitionCode): self
    {
        return new self(sprintf(
            'Attribute definition "%s" for content type "%s" was not found for bulk write.',
            $attributeDefinitionCode,
            $contentTypeCode,
        ));
    }

    public static function title(string $canonicalKey): self
    {
        return new self(sprintf('Title "%s" was not found for bulk write.', $canonicalKey));
    }
}
