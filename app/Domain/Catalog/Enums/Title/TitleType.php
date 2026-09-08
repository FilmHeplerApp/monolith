<?php

namespace App\Domain\Catalog\Enums\Title;

enum TitleType: string
{
    case ANIME = 'anime';
    case MOVIE = 'movie';
    case CARTOON = 'cartoon';
    case SERIES = 'series';
    case K_DRAMA = 'k-drama';
}
