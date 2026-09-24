<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;

interface ProviderClientFactoryContract
{
    public function make(
        ProviderSource     $source,
        ProviderDataSource $dataSource = ProviderDataSource::Api,
    ): ProviderClientContract;

    /**
     * @return array<string, ProviderClientContract>
     */
    public function enabled(): array;

    /**
     * @return list<ProviderSource>
     */
    public function enabledSources(): array;
}
