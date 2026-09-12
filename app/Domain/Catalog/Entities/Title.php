<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entities;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\Enums\Title\TitleUpdatedBy;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\Duration;
use App\Domain\Catalog\ValueObjects\Title\Embedding;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Domain\Catalog\ValueObjects\Title\TitleRating;
use App\Domain\Catalog\ValueObjects\Title\TitleUuid;
use DateTimeImmutable;

final readonly class Title
{
    /**
     * @param list<TitleAttribute> $attributes
     */
    public function __construct(
        public ?int               $id,
        public TitleUuid          $uuid,
        public TitleCanonicalKey  $canonicalKey,
        public LocalizedText      $title,
        public ?LocalizedText     $description,
        public ?string            $shortPlotRu,
        public ?Duration          $duration,
        public TitleContentType   $type,
        public TitleStatus        $status,
        public ?string            $posterUrl,
        public ?string            $bannerUrl,
        public TitleRating        $rating,
        public ?Embedding         $embedding,
        public TitleUpdatedBy     $updatedBy,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public array              $attributes = [],
    ) {
    }
}
