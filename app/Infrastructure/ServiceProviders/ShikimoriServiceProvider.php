<?php

declare(strict_types=1);

namespace App\Infrastructure\ServiceProviders;

use App\Infrastructure\Import\Dumps\DumpLocation;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriDumpReplayProviderClient;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriGraphQLClient;
use App\Infrastructure\Providers\Shikimori\Dumps\ShikimoriDumpReader;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use App\Infrastructure\Providers\Shikimori\ShikimoriConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class ShikimoriServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShikimoriDumpReplayProviderClient::class, static function (Application $app): ShikimoriDumpReplayProviderClient {
            $location = DumpLocation::fromConfig('shikimori');
            $disk = Storage::disk($location->disk);

            return new ShikimoriDumpReplayProviderClient(
                $app->make(ShikimoriTitleMapper::class),
                new ShikimoriDumpReader(
                    $disk->path($location->data),
                    $disk->path($location->checkpoint),
                    $disk->path($location->lock),
                ),
            );
        });
        $this->app->singleton(ShikimoriGraphQLClient::class, static fn (): ShikimoriGraphQLClient => new ShikimoriGraphQLClient(
            endpoint: ShikimoriConfig::endpoint(),
            userAgent: ShikimoriConfig::userAgent(),
            timeout: ShikimoriConfig::timeout(),
            throttleMs: ShikimoriConfig::throttleMs(),
            retries: ShikimoriConfig::retries(),
            retryBackoffMs: ShikimoriConfig::retryBackoffMs(),
        ));
    }
}
