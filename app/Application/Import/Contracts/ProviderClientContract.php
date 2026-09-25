<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use Generator;

interface ProviderClientContract
{
    public function source(): ProviderSource;

    /** @return Generator<int, ProviderTitle> */
    public function fetchTitles(?int $limit = null, int $offset = 0): Generator;

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitle;
}
