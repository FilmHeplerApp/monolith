<?php

declare(strict_types=1);

namespace App\Application\Media\Enums;

enum TitleImageKind: string
{
    case Poster = 'poster';
    case Banner = 'banner';

    public function variants(): array
    {
        return match ($this) {
            self::Poster => [ImageVariant::PosterFull, ImageVariant::PosterThumb],
            self::Banner => [ImageVariant::Banner],
        };
    }
}
