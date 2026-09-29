<?php

use App\Infrastructure\Providers\Shikimori\ShikimoriServiceProvider;
use App\Infrastructure\ServiceProviders\AppServiceProvider;
use App\Infrastructure\ServiceProviders\HorizonServiceProvider;

return [
    ShikimoriServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];
