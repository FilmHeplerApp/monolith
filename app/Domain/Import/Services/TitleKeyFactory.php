<?php

declare(strict_types=1);

namespace App\Domain\Import\Services;

use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Domain\Import\DTOs\TitleCandidate;
use Normalizer as IntlNormalizer;

final class TitleKeyFactory
{
    public const int NORMALIZER_VERSION = 2;


    public function create(TitleCandidate $candidate): TitleCanonicalKey
    {
        $rawTitle = $candidate->title?->getEn() ?? $candidate->title?->getRu() ?? '';

        $natural = sprintf(
            '%s|%s|%s|%s',
            $candidate->type->value,
            $candidate->format?->value ?? 'unknown',
            $candidate->releaseYear ?? '',
            $this->normalize($rawTitle),
        );

        return TitleCanonicalKey::createFromNatural($natural, self::NORMALIZER_VERSION);
    }


    private function normalize(string $value): string
    {
        $value = IntlNormalizer::normalize($value, IntlNormalizer::FORM_KD) ?: $value;
        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
