<?php

declare(strict_types=1);

namespace App\Infrastructure\ServiceProviders;

use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriGraphQLClient;
use App\Infrastructure\Providers\Shikimori\ShikimoriConfig;
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
    }
}
