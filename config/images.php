<?php

use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Enums\ResizeStrategy;

return [
    'disk' => 's3',

    'queue' => 'images',

    'variants' => [
        ImageVariant::PosterFull->value => [
            'max_width' => 1080,
            'max_height' => 1620,
            'strategy' => ResizeStrategy::Fit->value,
            'quality' => 82,
            'sharpen' => 5,
        ],
        ImageVariant::PosterThumb->value => [
            'max_width' => 360,
            'max_height' => 540,
            'strategy' => ResizeStrategy::Fit->value,
            'quality' => 78,
            'sharpen' => 8,
        ],
        ImageVariant::Banner->value => [
            'max_width' => 1280,
            'max_height' => 720,
            'strategy' => ResizeStrategy::Cover->value,
            'quality' => 80,
            'sharpen' => 0,
        ],
        ImageVariant::Avatar->value => [
            'max_width' => 256,
            'max_height' => 256,
            'strategy' => ResizeStrategy::Cover->value,
            'quality' => 82,
            'sharpen' => 0,
        ],
    ],

    'input' => [
        'max_bytes' => 15 * 1024 * 1024,
        'max_pixels' => 25_000_000,
        'allowed_mime' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
    ],

    'download' => [
        'allowed_schemes' => [
            'http',
            'https',
        ],
        'connect_timeout' => 10,
        'timeout' => 30,
    ],

    'output' => [
        'format' => 'webp',
        'cache_control' => 'public, max-age=31536000, immutable',
    ],
];
