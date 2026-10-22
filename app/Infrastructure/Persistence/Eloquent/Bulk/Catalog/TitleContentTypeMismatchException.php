<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Bulk\Catalog;

use RuntimeException;

final class TitleContentTypeMismatchException extends RuntimeException
{
    public static function titleAttribute(
        string $canonicalKey,
        string $titleContentTypeCode,
        string $attributeContentTypeCode,
    ): self {
        return new self(sprintf(
            'Title "%s" has content type "%s", but the title attribute uses "%s".',
            $canonicalKey,
            $titleContentTypeCode,
            $attributeContentTypeCode,
        ));
    }
}
