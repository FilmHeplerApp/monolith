<?php

declare(strict_types=1);

namespace App\Domain\Import\Contracts;

use App\Domain\Import\Enums\ProviderSource;
use App\Domain\Import\ValueObjects\ProviderTitle;

interface CatalogProviderClient
{
    public function source(): ProviderSource;

    /** @return iterable<ProviderTitle> */
    public function fetchTitles(int $limit = 50): iterable;

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitle;
}
