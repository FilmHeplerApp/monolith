<?php

namespace App\Infrastructure\ServiceProviders;

use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Infrastructure\Providers\ProviderClientFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ProviderClientFactoryContract::class,
            static fn (Application $app): ProviderClientFactory => new ProviderClientFactory(
                container: $app,
                clients: (array) config('import.providers.clients'),
                enabled: (array) config('import.providers.enabled'),
                isProduction: $app->isProduction(),
            ),
        );
    }

    public function boot(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName) {
            return 'Database\\Factories\\'.class_basename($modelName).'Factory';
        });
    }
}
