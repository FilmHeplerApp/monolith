<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

final readonly class ImportBatch
{
    public function __construct(
        public ImportCounters $counters,
        public int            $checkpoint,
        public array          $processedIds,
        public array          $rejections,
    ) {}
}
