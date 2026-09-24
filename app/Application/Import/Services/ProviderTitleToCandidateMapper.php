<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Application\Import\DTOs\ProviderTaxonomyItem;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderTitleMappingException;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Import\DTOs\CandidateAttribute;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\RejectionReason;

final readonly class ProviderTitleToCandidateMapper
{
    public function __construct(
        private ProviderTitleNormalizer $normalizer,
    ) {
    }

    public function map(ProviderTitle $title): TitleCandidate
    {
        if (trim($title->externalId) === '') {
            throw new ProviderTitleMappingException(
                RejectionReason::MALFORMED,
                message: 'Provider title has an empty external id.',
            );
        }

        return new TitleCandidate(
            source: $title->source->value,
            externalId: $title->externalId,
            title: LocalizedText::create($title->titleRu, $title->titleEn),
            description: LocalizedText::create(
                $this->normalizer->normalizeDescription($title->description),
                null,
            ),
            type: $this->mapType($title),
            status: $this->mapStatus($title->status),
            releaseYear: $title->year,
            providerScore: $title->rating,
            providerScoreCount: $title->ratingCount,
            durationMinutes: $title->durationMinutes,
            posterUrl: $title->posterUrl,
            bannerUrl: $title->bannerUrl,
            attributes: $this->mapAttributes($title),
        );
    }


    private function mapType(ProviderTitle $title): TitleContentType
    {
        $type = strtolower(trim($title->type));

        if ($title->source === ProviderSource::Shikimori) {
            return match ($type) {
                'tv', 'movie', 'ova', 'ona', 'special', 'tv_special' => TitleContentType::ANIME,
                'pv', 'cm' => throw new ProviderTitleMappingException(
                    RejectionReason::UNSUPPORTED_TYPE,
                    ['type' => $type],
                    "Unsupported Shikimori title type [$type].",
                ),
                default => throw new ProviderTitleMappingException(
                    RejectionReason::MALFORMED,
                    ['type' => $type],
                    "Unknown Shikimori title type [$type].",
                ),
            };
        }

        return TitleContentType::tryFrom($type)
            ?? throw new ProviderTitleMappingException(
                RejectionReason::UNSUPPORTED_TYPE,
                ['type' => $type],
                "Unsupported provider title type [$type].",
            );
    }

    private function mapStatus(string $status): TitleStatus
    {
        $status = strtolower(trim($status));

        if ($status === 'anons') {
            return TitleStatus::ANNOUNCED;
        }

        return TitleStatus::tryFrom($status)
            ?? throw new ProviderTitleMappingException(
                RejectionReason::MALFORMED,
                ['status' => $status],
                "Unknown provider title status [$status].",
            );
    }

    /** @return list<CandidateAttribute> */
    private function mapAttributes(ProviderTitle $title): array
    {
        $grouped = [];

        foreach ([...$title->genres, ...$title->studios] as $item) {
            $code = $this->attributeCode($item);
            $value = LocalizedText::create($item->nameRu, $item->nameEn);

            if ($value === null) {
                continue;
            }

            $key = ($value->getRu() ?? '')."\0".($value->getEn() ?? '');
            $grouped[$code][$key] = $value;
        }

        $attributes = [];

        foreach ($grouped as $code => $values) {
            $attributes[] = new CandidateAttribute($code, array_values($values));
        }

        return $attributes;
    }

    private function attributeCode(ProviderTaxonomyItem $item): string
    {
        return match ($item->kind) {
            'genre' => 'genres',
            'demographic' => 'target_audience',
            'theme' => 'tags',
            'studio' => 'studios',
            default => 'tags',
        };
    }
}
