<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Support;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Import\DTOs\TitleCandidate;

final class CandidateParent
{
    public static function create(
        ?string $titleRu = 'Стальной алхимик',
        ?string $titleEn = 'Fullmetal Alchemist',
        ?string $descriptionRu = 'Описание',
        ?string $descriptionEn = 'Description',
        TitleContentType $type = TitleContentType::ANIME,
        TitleStatus $status = TitleStatus::RELEASED,
        ?int $releaseYear = 2009,
        ?float $providerScore = 8.5,
        ?int $providerScoreCount = 100000,
        ?int $durationMinutes = 24,
        ?string $posterUrl = 'https://mock.local/poster.jpg',
        ?string $bannerUrl = null,
        string $source = 'shikimori',
        string $externalId = '52991',
        array $attributes = [],
    ): TitleCandidate {
        return new TitleCandidate(
            source: $source,
            externalId: $externalId,
            title: LocalizedText::create($titleRu, $titleEn),
            description: LocalizedText::create($descriptionRu, $descriptionEn),
            type: $type,
            status: $status,
            releaseYear: $releaseYear,
            providerScore: $providerScore,
            providerScoreCount: $providerScoreCount,
            durationMinutes: $durationMinutes,
            posterUrl: $posterUrl,
            bannerUrl: $bannerUrl,
            attributes: $attributes,
        );
    }
}
