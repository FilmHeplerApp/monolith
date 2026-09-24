<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

final readonly class ImportRunCheckpoint
{
    public function __construct(
        public int            $runId,
        public int            $checkpoint,
        public ImportCounters $counters,
        public ?int           $limit,
    ) {
    }
}
