<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Mock;

use App\Application\Import\Contracts\ProviderTitleMapperContract;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;
use InvalidArgumentException;

final readonly class MockTitleMapper implements ProviderTitleMapperContract
{
    public function source(): ProviderSource
    {
        return ProviderSource::Mock;
    }

    public function map(ProviderTitleRecord $record): ProviderTitle
    {
        if ($record->source !== $this->source()) {
            throw new InvalidArgumentException('Mock mapper requires a mock record.');
        }

        return new ProviderTitle(...array_replace($record->payload, [
            'source' => $record->source,
            'externalId' => $record->externalId,
        ]));
    }
}
