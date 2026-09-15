<?php

namespace App\Infrastructure\ServiceProviders;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Infrastructure\Persistence\Eloquent\Bulk\Catalog\CatalogBulkWriter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CatalogBulkWriterContract::class,
            CatalogBulkWriter::class,
        );
    }

    public function boot(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName) {
            return 'Database\\Factories\\' . class_basename($modelName) . 'Factory';
        });
    }
}
