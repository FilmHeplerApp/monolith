<?php

use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use App\Infrastructure\Providers\Mock\MockTitleMapper;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriDumpProviderClient;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriProviderClient;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;

return [
    'providers' => [
        'enabled' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('IMPORT_PROVIDERS', ProviderSource::Mock->value)),
        ))),
        'mappers' => [
            'mock' => ['api' => MockTitleMapper::class],
            'shikimori' => ['api' => ShikimoriTitleMapper::class, 'dump' => ShikimoriTitleMapper::class],
        ],
        'clients' => [
            ProviderSource::Mock->value => [
                ProviderDataSource::Api->value => MockProviderClient::class,
            ],
            ProviderSource::Shikimori->value => [
                ProviderDataSource::Api->value => ShikimoriProviderClient::class,
                ProviderDataSource::Dump->value => ShikimoriDumpProviderClient::class,
            ],
        ],
    ],
    'dumps' => [
        ProviderSource::Shikimori->value => [
            'disk' => env('SHIKIMORI_DUMP_DISK', 'local'),
            'data' => env('SHIKIMORI_DUMP_DATA_PATH', 'import/shikimori_anime.ndjson'),
            'checkpoint' => env('SHIKIMORI_DUMP_CHECKPOINT_PATH', 'import/shikimori_anime.checkpoint'),
            'lock' => env('SHIKIMORI_DUMP_LOCK_PATH', 'import/shikimori_dump.lock'),
            'taxonomy' => env('SHIKIMORI_DUMP_TAXONOMY_PATH', 'import/shikimori_genres.ndjson'),
        ],
    ],
    'filter' => [
        'allowed_types' => [TitleContentType::ANIME->value],
        'min_release_year' => (int) (env('IMPORT_MIN_RELEASE_YEAR') ?: 1895),
    ],
    'checkpoint_every' => (int) env('IMPORT_CHECKPOINT_EVERY', 50),
];
