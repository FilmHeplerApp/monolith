<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\Enums\Title\TitleUpdatedBy;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\Duration;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Domain\Catalog\ValueObjects\Title\TitleUuid;

final readonly class TitleData
{
    public function __construct(
        public TitleUuid         $uuid,
        public TitleCanonicalKey $canonicalKey,
        public LocalizedText     $title,
        public ?LocalizedText    $description,
        public ?string           $shortPlotRu,
        public ?Duration         $duration,
        public TitleContentType  $type,
        public TitleStatus       $status,
        public ?string           $posterUrl,
        public ?string           $bannerUrl,
        public TitleUpdatedBy    $updatedBy = TitleUpdatedBy::PROCESS,
    ) {
    }
}
