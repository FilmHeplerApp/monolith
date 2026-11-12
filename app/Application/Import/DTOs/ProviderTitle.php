<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Application\Import\Enums\ProviderSource;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use InvalidArgumentException;

final readonly class ProviderTitle
{
    public function __construct(
        public ProviderSource $source,
        public string         $externalId,
        public ?LocalizedText $title,
        public ?LocalizedText $description,
        public ?int           $year,
        public ?int           $durationMinutes,
        public ?float         $rating,
        public ?string        $posterUrl,
        public ?string        $bannerUrl,
        public string         $type,
        public string         $status,
        public ?TitleFormat   $format = null,
        public ?int           $ratingCount = null,
        public array          $attributes = [],
    ) {
        foreach ($attributes as $attribute) {
            if (!$attribute instanceof ProviderAttribute) {
                throw new InvalidArgumentException('Provider title attributes must contain ProviderAttribute instances.');
            }
        }
    }
}
