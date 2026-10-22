<?php

use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use App\Infrastructure\Providers\Shikimori\Clients\DumpReplayProviderClient;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriProviderClient;

return [
    'providers' => [
        'enabled' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('IMPORT_PROVIDERS', ProviderSource::Mock->value)),
        ))),
        'clients' => [
            ProviderSource::Mock->value => [
                ProviderDataSource::Api->value => MockProviderClient::class,
            ],
            ProviderSource::Shikimori->value => [
                ProviderDataSource::Api->value => ShikimoriProviderClient::class,
                ProviderDataSource::Dump->value => DumpReplayProviderClient::class,
            ],
        ],
    ],
    'filter' => [
        'allowed_types' => [TitleContentType::ANIME->value],
        'min_release_year' => filled(env('IMPORT_MIN_RELEASE_YEAR'))
            ? (int) env('IMPORT_MIN_RELEASE_YEAR')
            : null,
    ],
    'checkpoint_every' => (int) env('IMPORT_CHECKPOINT_EVERY', 50),
];
