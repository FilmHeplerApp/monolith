<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Services\ProviderImportContext;

interface ProviderImportContextFactoryContract
{
    public function create(ProviderSource $provider, ProviderDataSource $source): ProviderImportContext;
}
