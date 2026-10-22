<?php

declare(strict_types=1);

namespace App\Infrastructure\ServiceProviders;

use App\Infrastructure\Providers\Shikimori\Clients\DumpReplayProviderClient;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriGraphQLClient;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use App\Infrastructure\Providers\Shikimori\ShikimoriConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ShikimoriServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DumpReplayProviderClient::class, static fn (Application $app) => new DumpReplayProviderClient(
            $app->make(ShikimoriTitleMapper::class), (string) config('shikimori.dump_path'),
        ));
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
