<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Factories;

use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Contracts\ProviderImportContextFactoryContract;
use App\Application\Import\Contracts\ProviderTitleMapperContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Services\ProviderImportContext;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final readonly class ProviderImportContextFactory implements ProviderImportContextFactoryContract
{
    public function __construct(
        private Container                     $container,
        private ProviderClientFactoryContract $clients,
        private array                         $mappers,
    ) {}

    /**
     * @throws BindingResolutionException
     */
    public function create(ProviderSource $provider, ProviderDataSource $source): ProviderImportContext
    {
        $class = $this->mappers[$provider->value][$source->value] ?? null;
        if (!is_string($class) || !is_a($class, ProviderTitleMapperContract::class, true)) {
            throw new InvalidArgumentException("Invalid or missing [$source->value] mapper for provider [$provider->value].");
        }

        $mapper = $this->container->make($class);
        if ($mapper->source() !== $provider) {
            throw new InvalidArgumentException('Import mapper does not match the requested provider.');
        }

        $client = $this->clients->make($provider, $source);
        if ($client->source() !== $provider) {
            throw new InvalidArgumentException('Import client does not match the requested provider.');
        }

        return new ProviderImportContext($client, $mapper);
    }
}
