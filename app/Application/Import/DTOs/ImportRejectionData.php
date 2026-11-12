<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Domain\Import\Enums\RejectionReason;

final readonly class ImportRejectionData
{
    public function __construct(
        public string          $externalId,
        public RejectionReason $reason,
        public array           $context = [],
    ) {}
}
