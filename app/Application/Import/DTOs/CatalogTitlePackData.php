<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;

final readonly class CatalogTitlePackData
{
    /**
     * @param list<CatalogAttributePackData> $attributes
     */
    public function __construct(
        public TitleCanonicalKey $canonicalKey,
        public LocalizedText     $title,
        public ?LocalizedText    $description,
        public ?int              $releaseYear,
        public ?int              $durationMinutes,
        public TitleContentType  $contentType,
        public TitleStatus       $status,
        public ?string           $posterUrl,
        public ?string           $bannerUrl,
        public bool              $isIncomplete,
        public array             $attributes,
    ) {
    }
}
