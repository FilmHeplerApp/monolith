<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Exceptions\ProviderTitleMappingException;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\ReleaseYear;
use App\Domain\Import\DTOs\CandidateAttribute;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\RejectionReason;

final readonly class ProviderTitleToCandidateMapper
{
    public function __construct(
        private ProviderTitleNormalizer $normalizer,
    ) {}

    public function map(ProviderTitle $title): TitleCandidate
    {
        if (trim($title->externalId) === '') {
            throw new ProviderTitleMappingException(
                RejectionReason::MALFORMED,
                message: 'Provider title has an empty external id.',
            );
        }

        if ($title->rating !== null && (!is_finite($title->rating) || $title->rating < 0 || $title->rating > 10)) {
            throw new ProviderTitleMappingException(
                RejectionReason::MALFORMED,
                ['rating' => $title->rating],
                "Provider title rating must be between 0 and 10, got {$title->rating}.",
            );
        }

        if ($title->durationMinutes !== null && $title->durationMinutes <= 0) {
            throw new ProviderTitleMappingException(
                RejectionReason::MALFORMED,
                ['durationMinutes' => $title->durationMinutes],
                "Provider title duration must be positive, got {$title->durationMinutes}.",
            );
        }

        if ($title->year !== null && ($title->year < ReleaseYear::MIN_YEAR || $title->year > ReleaseYear::MAX_YEAR)) {
            throw new ProviderTitleMappingException(RejectionReason::MALFORMED, ['year' => $title->year], 'Provider title year is out of range.');
        }

        return new TitleCandidate(
            source: $title->source->value,
            externalId: $title->externalId,
            title: $title->title,
            description: $this->normalizer->normalizeLocalizedDescription($title->description),
            type: $this->mapType($title),
            status: $this->mapStatus($title->status),
            releaseYear: $title->year,
            providerScore: $title->rating,
            providerScoreCount: $title->ratingCount,
            durationMinutes: $title->durationMinutes,
            posterUrl: $title->posterUrl,
            bannerUrl: $title->bannerUrl,
            attributes: $this->mapAttributes($title),
            format: $title->format,
        );
    }


    private function mapType(ProviderTitle $title): TitleContentType
    {
        $type = strtolower(trim($title->type));


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

        return TitleStatus::tryFrom($status)
            ?? throw new ProviderTitleMappingException(
                RejectionReason::MALFORMED,
                ['status' => $status],
                "Unknown provider title status [$status].",
            );
    }


    private function mapAttributes(ProviderTitle $title): array
    {
        $grouped = [];

        foreach ([...$title->genres, ...$title->studios] as $item) {
            $code = $item->attributeCode;
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
}
