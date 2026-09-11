<?php

namespace App\Infrastructure\ServiceProviders;

use App\Application\Import\Contracts\ProviderClientInterface;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProviderClientInterface::class, MockProviderClient::class);
    }

    public function boot(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName) {
            return 'Database\\Factories\\'.class_basename($modelName).'Factory';
        });
    }
}
