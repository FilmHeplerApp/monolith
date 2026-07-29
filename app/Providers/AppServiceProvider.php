<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Import\Contracts\CatalogProviderClient;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CatalogProviderClient::class, MockProviderClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
