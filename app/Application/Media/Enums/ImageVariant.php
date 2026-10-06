<?php

declare(strict_types=1);

namespace App\Application\Media\Enums;

enum ImageVariant: string
{
    case PosterFull = 'poster_full';
    case PosterThumb = 'poster_thumb';
    case Banner = 'banner';
    case Avatar = 'avatar';
}
