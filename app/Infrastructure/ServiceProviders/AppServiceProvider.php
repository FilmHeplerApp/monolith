<?php

namespace App\Infrastructure\ServiceProviders;

use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriGraphQLClient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShikimoriGraphQLClient::class, function ($app): ShikimoriGraphQLClient {
            $config = $app['config']->get('shikimori');

            return new ShikimoriGraphQLClient(
                endpoint: $config['endpoint'],
                userAgent: $config['user_agent'],
                timeout: $config['timeout'],
                throttleMs: $config['throttle_ms'],
                retries: $config['retries'],
                retryBackoffMs: $config['retry_backoff_ms'],
            );
        });
    }

    public function boot(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName) {
            return 'Database\\Factories\\' . class_basename($modelName) . 'Factory';
        });
    }
}
