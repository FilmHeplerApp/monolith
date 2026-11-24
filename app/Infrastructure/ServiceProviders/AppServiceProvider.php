<?php

namespace App\Infrastructure\ServiceProviders;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Catalog\Contracts\TitleRepositoryContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\Contracts\TitleImageIngestionOutboxContract;
use App\Application\Media\Contracts\TitleImageIngestionQueueContract;
use App\Application\Media\DTOs\TitleImageIngestionRelaySettings;
use App\Domain\Users\Repositories\UserPreferenceRepository;
use App\Domain\Users\Repositories\UserRepository;
use App\Infrastructure\Media\Compression\GdImageCompressor;
use App\Infrastructure\Media\Compression\ImageCompressor;
use App\Infrastructure\Media\Compression\SpecImageCompressor;
use App\Infrastructure\Media\Config\ImageConfig;
use App\Infrastructure\Media\Download\HttpImageDownloader;
use App\Infrastructure\Media\Download\IpAddressClassifier;
use App\Infrastructure\Media\Download\PublicHostResolver;
use App\Infrastructure\Media\Queue\LaravelTitleImageIngestionQueue;
use App\Infrastructure\Media\Storage\S3ImageStorage;
use App\Infrastructure\Persistence\Eloquent\Bulk\Catalog\CatalogBulkWriter;
use App\Infrastructure\Persistence\Eloquent\Media\EloquentTitleImageIngestionOutbox;
use App\Infrastructure\Persistence\Eloquent\Repositories\Catalog\EloquentTitleRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\Users\EloquentUserPreferenceRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\Users\EloquentUserRepository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CatalogBulkWriterContract::class,
            CatalogBulkWriter::class,
        );
        $this->app->bind(
            TitleRepositoryContract::class,
            EloquentTitleRepository::class,
        );
        $this->app->bind(
            TitleImageIngestionOutboxContract::class,
            EloquentTitleImageIngestionOutbox::class,
        );
        $this->app->bind(
            TitleImageIngestionQueueContract::class,
            LaravelTitleImageIngestionQueue::class,
        );
        $this->app->bind(
            TitleImageIngestionRelaySettings::class,
            static fn (): TitleImageIngestionRelaySettings => new TitleImageIngestionRelaySettings(
                publishLimit: ImageConfig::outboxPublishLimit(),
                chunk: ImageConfig::outboxChunk(),
                maxQueueSize: ImageConfig::outboxMaxQueueSize(),
                staleAfterMinutes: ImageConfig::outboxStaleAfterMinutes(),
            ),
        );
        $this->app->bind(
            UserRepository::class,
            EloquentUserRepository::class,
        );
        $this->app->bind(
            UserPreferenceRepository::class,
            EloquentUserPreferenceRepository::class,
        );
        $this->app->singleton(
            ImageManagerInterface::class,
            static fn (): ImageManager => new ImageManager(new Driver),
        );
        $this->app->bind(
            SpecImageCompressor::class,
            GdImageCompressor::class,
        );
        $this->app->bind(
            ImageCompressorContract::class,
            ImageCompressor::class,
        );
        $this->app->bind(
            ImageDownloaderContract::class,
            static fn (): HttpImageDownloader => new HttpImageDownloader(
                new PublicHostResolver(new IpAddressClassifier),
            ),
        );
        $this->app->bind(
            ImageStorageContract::class,
            S3ImageStorage::class,
        );
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(!$this->app->isProduction());

        $this->app->make(ProviderClientFactoryContract::class);

        Factory::guessFactoryNamesUsing(function (string $modelName) {
            return 'Database\\Factories\\'.class_basename($modelName).'Factory';
        });
    }
}
