<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums\Title;

enum TitleFormat: string
{
    case TV = 'tv';
    case MOVIE = 'movie';
    case TV_SHORT = 'tv_short';
    case SPECIAL = 'special';
    case TV_SPECIAL = 'tv_special';
    case OVA = 'ova';
    case ONA = 'ona';
    case MUSIC = 'music';
    case PV = 'pv';
    case CM = 'cm';
}
