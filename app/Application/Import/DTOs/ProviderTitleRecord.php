<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Application\Import\Enums\ProviderSource;
use InvalidArgumentException;

final readonly class ProviderTitleRecord
{
    public function __construct(
        public ProviderSource $source,
        public string         $externalId,
        public array          $payload,
    ) {
        if (trim($externalId) === '') {
            throw new InvalidArgumentException('Provider title record must have an external id.');
        }
    }
}
