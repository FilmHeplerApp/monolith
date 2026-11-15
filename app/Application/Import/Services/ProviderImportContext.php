<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderTitleMapperContract;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;
use InvalidArgumentException;
use RuntimeException;

final readonly class ProviderImportContext
{
    public function __construct(
        public ProviderClientContract       $client,
        private ProviderTitleMapperContract $mapper,
    ) {
        if ($client->source() !== $mapper->source()) {
            throw new InvalidArgumentException('Import client and mapper must belong to the same provider.');
        }
    }

    public function source(): ProviderSource
    {
        return $this->client->source();
    }

    public function interpret(ProviderTitleRecord $record): ProviderTitle
    {
        if ($record->source !== $this->source()) {
            throw new RuntimeException('Import record does not match the provider of its context.');
        }

        $title = $this->mapper->map($record);
        if ($title->source !== $record->source || $title->externalId !== $record->externalId) {
            throw new RuntimeException('Mapped title identity does not match its raw record.');
        }

        return $title;
    }
}
