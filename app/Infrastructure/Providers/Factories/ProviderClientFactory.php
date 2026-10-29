<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Factories;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

final readonly class ProviderClientFactory implements ProviderClientFactoryContract
{
    private array $classes;
    private array $sources;


    public function __construct(
        private Container $container,
        array             $clients,
        array             $enabled,
        private bool      $isProduction,
    ) {
        $classes = [];
        foreach ($clients as $provider => $sources) {
            $source = $this->toSource($provider);
            foreach (is_array($sources) ? $sources : [ProviderDataSource::Api->value => $sources] as $dataSource => $class) {
                $dataSource = ProviderDataSource::tryFrom($dataSource)
                    ?? throw new InvalidArgumentException("Unknown import data source [$dataSource].");
                if (!is_string($class) || !is_a($class, ProviderClientContract::class, true)) {
                    throw new InvalidArgumentException("Invalid client registered for provider [$provider].");
                }
                $classes[$this->key($source, $dataSource)] = $class;
            }
        }
        $this->classes = $classes;
        $this->sources = array_map($this->toSource(...), array_values(array_unique($enabled)));

        foreach ($this->sources as $source) {
            $registered = false;
            foreach (ProviderDataSource::cases() as $dataSource) {
                $class = $this->classes[$this->key($source, $dataSource)] ?? null;
                if ($class !== null) {
                    $this->assertAllowed($class);
                    $registered = true;
                }
            }
            if (!$registered) {
                throw new InvalidArgumentException("No client registered for provider [$source->value].");
            }
        }
    }

    public function make(ProviderSource $source, ProviderDataSource $dataSource = ProviderDataSource::Api): ProviderClientContract
    {
        $class = $this->classes[$this->key($source, $dataSource)]
            ?? throw new InvalidArgumentException("No [$dataSource->value] client registered for provider [$source->value].");
        $this->assertAllowed($class);

        return $this->container->make($class);
    }

    public function enabled(): array
    {
        $clients = [];
        foreach ($this->sources as $source) {
            $clients[$source->value] = $this->make($source);
        }

        return $clients;
    }

    public function getEnabledSources(): array
    {
        return $this->sources;
    }


    private function assertAllowed(string $class): void
    {
        if (is_a($class, MockProviderClient::class, true) && $this->isProduction) {
            throw new RuntimeException('MockProviderClient is not allowed in production.');
        }
    }

    private function key(ProviderSource $source, ProviderDataSource $dataSource): string
    {
        return $source->value."\0".$dataSource->value;
    }

    private function toSource(string $source): ProviderSource
    {
        return ProviderSource::tryFrom($source)
            ?? throw new InvalidArgumentException("Unknown import provider [$source].");
    }
}
