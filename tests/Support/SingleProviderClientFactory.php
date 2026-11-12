<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;

final readonly class SingleProviderClientFactory implements ProviderClientFactoryContract
{
    public function __construct(private ProviderClientContract $client) {}

    public function make(
        ProviderSource     $source,
        ProviderDataSource $dataSource = ProviderDataSource::Api,
    ): ProviderClientContract {
        return $this->client;
    }

    public function enabled(): array
    {
        return [$this->client->source()->value => $this->client];
    }

    public function getEnabledSources(): array
    {
        return [$this->client->source()];
    }
}
