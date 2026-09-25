<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Factories;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

final readonly class ProviderClientFactory implements ProviderClientFactoryContract
{
    /**
     * @param  array<string, array<string, class-string<ProviderClientContract>>>  $clients
     * @param  list<string>  $enabled
     */
    public function __construct(
        private Container $container,
        private array     $clients,
        private array     $enabled,
        private bool      $isProduction,
    ) {}

    /**
     * @throws BindingResolutionException
     */
    public function make(
        ProviderSource     $source,
        ProviderDataSource $dataSource = ProviderDataSource::Api,
    ): ProviderClientContract {
        $class = $this->clients[$source->value][$dataSource->value]
            ?? throw new InvalidArgumentException(
                "No [$dataSource->value] client registered for provider [$source->value].",
            );

        if ($class === MockProviderClient::class && $this->isProduction) {
            throw new RuntimeException('MockProviderClient is not allowed in production.');
        }

        return $this->container->make($class);
    }

    /**
     * @throws BindingResolutionException
     */
    public function enabled(): array
    {
        $clients = [];

        foreach ($this->enabled as $source) {
            $clients[$source] = $this->make($this->toSource($source), ProviderDataSource::Api);
        }

        return $clients;
    }

    public function getEnabledSources(): array
    {
        return array_map($this->toSource(...), $this->enabled);
    }


    private function toSource(string $source): ProviderSource
    {
        return ProviderSource::tryFrom($source)
            ?? throw new InvalidArgumentException("Unknown import provider [$source].");
    }
}
