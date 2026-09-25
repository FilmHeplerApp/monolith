<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Application\Import\Enums\ProviderSource;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;

final readonly class ProviderTitle
{
    public function __construct(
        public ProviderSource $source,
        public string         $externalId,
        public ?LocalizedText $title,
        public ?LocalizedText $description,
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
