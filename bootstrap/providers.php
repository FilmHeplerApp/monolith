<?php

use App\Infrastructure\ServiceProviders\AppServiceProvider;
use App\Infrastructure\ServiceProviders\HorizonServiceProvider;
use App\Infrastructure\ServiceProviders\ShikimoriServiceProvider;

return [
    ShikimoriServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];
