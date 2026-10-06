<?php

namespace App\Infrastructure\ServiceProviders;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Media\Contracts\ImageCompressorContract;
use App\Infrastructure\Media\Compression\GdImageCompressor;
use App\Infrastructure\Persistence\Eloquent\Bulk\Catalog\CatalogBulkWriter;
use App\Infrastructure\Providers\Factories\ProviderClientFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ProviderClientFactoryContract::class,
            static fn(Application $app): ProviderClientFactory => new ProviderClientFactory(
                container: $app,
                clients: (array)config('import.providers.clients'),
                enabled: (array)config('import.providers.enabled'),
                isProduction: $app->isProduction(),
            ),
        );
        $this->app->bind(
            CatalogBulkWriterContract::class,
            CatalogBulkWriter::class,
        );
        $this->app->singleton(
            ImageManagerInterface::class,
            static fn(): ImageManager => new ImageManager(new Driver()),
        );
        $this->app->bind(
            ImageCompressorContract::class,
            GdImageCompressor::class,
        );
    }

    public function boot(): void
    {
        $this->app->make(ProviderClientFactoryContract::class);

        Factory::guessFactoryNamesUsing(function (string $modelName) {
            return 'Database\\Factories\\' . class_basename($modelName) . 'Factory';
        });
    }
}
