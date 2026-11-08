<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderTitleMapperContract;
use InvalidArgumentException;

final readonly class ProviderImportContext
{
    public function __construct(
        public ProviderClientContract      $client,
        public ProviderTitleMapperContract $mapper,
    ) {
        if ($client->source() !== $mapper->source()) {
            throw new InvalidArgumentException('Import client and mapper must belong to the same provider.');
        }
    }
}
