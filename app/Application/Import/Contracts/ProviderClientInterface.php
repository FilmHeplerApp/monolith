<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Application\Import\DTO\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;

interface ProviderClientInterface
{
    public function source(): ProviderSource;

    /** @return iterable<ProviderTitle> */
    public function fetchTitles(int $limit = 50): iterable;

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitle;
}
