<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;

final readonly class TitleCandidate
{
    public function __construct(
        public string           $source,
        public string           $externalId,
        public ?LocalizedText   $title,
        public ?LocalizedText   $description,
        public TitleContentType $type,
        public TitleStatus      $status,
        public ?int             $releaseYear,
        public ?float           $providerScore,
        public ?int             $providerScoreCount,
        public ?int             $durationMinutes,
        public ?string          $posterUrl,
        public ?string          $bannerUrl,
        /** @var list<CandidateAttribute> */
        public array            $attributes = [],
    ) {
    }
}
