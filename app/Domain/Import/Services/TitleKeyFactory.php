<?php

declare(strict_types=1);

namespace App\Domain\Import\Services;

use App\Domain\Import\ValueObjects\TitleCandidate;
use App\Domain\Import\ValueObjects\TitleKey;
use Normalizer;

final class TitleKeyFactory
{
    public const int NORMALIZER_VERSION = 1;

    public function create(TitleCandidate $candidate): TitleKey
    {
        $rawTitle = $candidate->title?->getEn() ?? $candidate->title?->getRu() ?? '';

        $natural = sprintf(
            '%s|%s|%s',
            $candidate->type->value,
            $candidate->releaseYear ?? '',
            $this->normalize($rawTitle),
        );

        return TitleKey::fromNatural($natural);
    }

    private function normalize(string $value): string
    {
        $value = Normalizer::normalize($value, Normalizer::FORM_KD) ?: $value;
        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
