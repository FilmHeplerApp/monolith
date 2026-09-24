<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori;

use App\Infrastructure\Providers\Shikimori\Clients\DumpReplayProviderClient;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriGraphQLClient;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ShikimoriServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShikimoriGraphQLClient::class, static fn (): ShikimoriGraphQLClient => new ShikimoriGraphQLClient(
            endpoint: ShikimoriConfig::endpoint(),
            userAgent: ShikimoriConfig::userAgent(),
            timeout: ShikimoriConfig::timeout(),
            throttleMs: ShikimoriConfig::throttleMs(),
            retries: ShikimoriConfig::retries(),
            retryBackoffMs: ShikimoriConfig::retryBackoffMs(),
        ));

        $this->app->bind(
            DumpReplayProviderClient::class,
            static fn (Application $app): DumpReplayProviderClient => new DumpReplayProviderClient(
                mapper: $app->make(ShikimoriTitleMapper::class),
                path: (string) config('shikimori.dump_path'),
            ),
        );
    }
}
