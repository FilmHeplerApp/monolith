<?php

namespace App\Domain\Catalog\Enums\Title;

enum TitleContentType: string
{
    case ANIME = 'anime';
    case MOVIE = 'movie';
    case SERIES = 'series';
    case K_DRAMA = 'k-drama';
    case CARTOON = 'cartoon';
}
