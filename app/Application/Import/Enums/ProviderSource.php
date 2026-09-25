<?php

declare(strict_types=1);

namespace App\Application\Import\Enums;

enum ProviderSource: string
{
    case Shikimori = 'shikimori';
    case Anilist = 'anilist';
    case Jikan = 'jikan';
    case Tmdb = 'tmdb';
    case Mock = 'mock';
}
