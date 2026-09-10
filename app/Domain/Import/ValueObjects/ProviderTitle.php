<?php

declare(strict_types=1);

namespace App\Domain\Import\ValueObjects;

use App\Domain\Import\Enums\ProviderSource;
use App\Domain\Import\Exceptions\InvalidProviderTitleException;

final readonly class ProviderTitle
{
    public function __construct(
        public ProviderSource $source,
        public string         $externalId,
        public ?string        $titleRu,
        public ?string        $titleEn,
        public ?string        $description,
        public array          $genres,
        public ?int           $year,
        public ?int           $durationMinutes,
        public ?float         $rating,
        public ?string        $posterUrl,
        public ?string        $bannerUrl,
        public string         $type,
        public string         $status,
    ) {
        if (trim($externalId) === '') {
            throw InvalidProviderTitleException::emptyExternalId();
        }

        if ($this->isBlank($titleRu) && $this->isBlank($titleEn)) {
            throw InvalidProviderTitleException::missingTitle();
        }

        if ($rating !== null && ($rating < 0.0 || $rating > 10.0)) {
            throw InvalidProviderTitleException::ratingOutOfRange($rating);
        }

        if ($durationMinutes !== null && $durationMinutes <= 0) {
            throw InvalidProviderTitleException::nonPositiveDuration($durationMinutes);
        }
    }

    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
