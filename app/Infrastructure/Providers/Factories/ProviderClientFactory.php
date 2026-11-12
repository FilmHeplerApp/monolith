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
        $sources = [];
        foreach ($enabled as $provider) {
            $source = $this->toSource($provider);
            $sources[$source->value] = $source;
        }

        $classes = $this->normalizeClients($clients, $sources);
        $missingRegistrations = array_diff_key($sources, $classes);

        if ($missingRegistrations !== []) {
            $provider = array_key_first($missingRegistrations);
            throw new InvalidArgumentException("No client registered for provider [$provider].");
        }

        $this->classes = $classes;
        $this->sources = array_values($sources);
    }

    public function make(ProviderSource $source, ProviderDataSource $dataSource = ProviderDataSource::Api): ProviderClientContract
    {
        if (!in_array($source, $this->sources, true)) {
            throw new InvalidArgumentException("Provider [$source->value] is disabled. Enabled: {$this->enabledList()}.");
        }

        $class = $this->classes[$source->value][$dataSource->value]
            ?? throw new InvalidArgumentException("No [$dataSource->value] client registered for provider [$source->value].");

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


    private function enabledList(): string
    {
        return $this->sources === []
            ? 'none'
            : implode(', ', array_map(static fn (ProviderSource $source): string => $source->value, $this->sources));
    }

    private function assertAllowed(string $class): void
    {
        if ($this->isProduction && is_a($class, MockProviderClient::class, true)) {
            throw new RuntimeException('MockProviderClient is not allowed in production.');
        }
    }

    private function normalizeClients(array $clients, array $enabledSources): array
    {
        $classes = [];

        foreach ($clients as $provider => $registrations) {
            $source = $this->toSource($provider);
            $isEnabled = isset($enabledSources[$source->value]);
            $registrations = is_array($registrations)
                ? $registrations
                : [ProviderDataSource::Api->value => $registrations];

            foreach ($registrations as $dataSource => $class) {
                $dataSource = $this->toDataSource($dataSource);

                if (!is_string($class) || !is_a($class, ProviderClientContract::class, true)) {
                    throw new InvalidArgumentException("Invalid client registered for provider [$provider].");
                }

                if ($isEnabled) {
                    $this->assertAllowed($class);
                    $classes[$source->value][$dataSource->value] = $class;
                }
            }
        }

        return $classes;
    }

    private function toDataSource(int|string $dataSource): ProviderDataSource
    {
        return (is_string($dataSource) ? ProviderDataSource::tryFrom($dataSource) : null)
            ?? throw new InvalidArgumentException("Unknown import data source [$dataSource].");
    }

    private function toSource(mixed $source): ProviderSource
    {
        if (!is_string($source)) {
            throw new InvalidArgumentException('Import provider names must be strings.');
        }

        return ProviderSource::tryFrom($source)
            ?? throw new InvalidArgumentException("Unknown import provider [$source].");
    }
}
