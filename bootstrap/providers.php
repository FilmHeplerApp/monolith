<?php

use App\Infrastructure\Providers\Shikimori\ShikimoriServiceProvider;
use App\Infrastructure\ServiceProviders\AppServiceProvider;
use App\Infrastructure\ServiceProviders\HorizonServiceProvider;
use App\Infrastructure\ServiceProviders\ImportServiceProvider;

return [
    ShikimoriServiceProvider::class,
    ImportServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];
