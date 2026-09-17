<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Application\Import\Enums\ProviderSource;

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
    }
}
