<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

final readonly class ImportResult
{
    public function __construct(
        public int            $runId,
        public ImportCounters $counters,
        public int            $checkpoint,
        public bool           $resumed,
    ) {
    }
}
