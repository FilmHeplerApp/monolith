<?php

namespace App\Enums\Title;

enum ContentType: string
{
    case ANIME = 'anime';
    case MOVIE = 'movie';
    case SERIES = 'series';
    case K_DRAMA = 'k-drama';
    case CARTOON = 'cartoon';
}
