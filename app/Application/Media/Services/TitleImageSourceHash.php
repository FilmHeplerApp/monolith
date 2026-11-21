<?php

declare(strict_types=1);

namespace App\Application\Media\Services;

final class TitleImageSourceHash
{
    public static function of(string $url): string
    {
        return hash('sha256', $url);
    }
}
