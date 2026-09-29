<?php

use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Mock\MockProviderClient;

return [
    'providers' => [
        'enabled' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('IMPORT_PROVIDERS', ProviderSource::Mock->value)),
        ))),
        'clients' => [
            ProviderSource::Mock->value => MockProviderClient::class,
        ],
    ],
];
