<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

use App\Application\Media\Enums\TitleImageKind;

final readonly class TitleImageWork
{
    public function __construct(
        public int            $requestId,
        public int            $titleId,
        public int            $version,
        public TitleImageKind $kind,
        public string         $sourceUrl,
        public string         $sourceHash,
        public string         $token,
    ) {}
}
