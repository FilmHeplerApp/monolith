<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;
use Generator;

interface ProviderClientContract
{
    public function source(): ProviderSource;

    public function fetchTitles(?int $limit = 50, int $offset = 0): Generator;

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitleRecord;
}
