<?php

declare(strict_types=1);

namespace App\Application\Media\Enums;

enum ImageVariant: string
{
    case PosterFull = 'poster_full';
    case PosterThumb = 'poster_thumb';
    case Banner = 'banner';
    case Avatar = 'avatar';

    public function objectKey(string $ingestionUuid): string
    {
        return sprintf('%s/%s/%s.webp', $this->directory(), $ingestionUuid, $this->filename());
    }


    private function directory(): string
    {
        return match($this) {
            self::PosterFull,
            self::PosterThumb => 'titles/posters',
            self::Banner => 'titles/banners',
            self::Avatar => 'users/avatars',
        };
    }

    private function filename(): string
    {
        return match($this) {
            self::PosterFull => 'full',
            self::PosterThumb => 'thumb',
            self::Banner => 'banner',
            self::Avatar => 'avatar',
        };
    }
}
