<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use InvalidArgumentException;

final class ImportCounters
{
    public function __construct(
        private(set) int $fetched = 0,
        private(set) int $accepted = 0,
        private(set) int $flagged = 0,
        private(set) int $rejected = 0,
    ) {
        if (min($this->fetched, $this->accepted, $this->flagged, $this->rejected) < 0) {
            throw new InvalidArgumentException('Import counters cannot be negative.');
        }

        if ($this->fetched !== $this->accepted + $this->flagged + $this->rejected) {
            throw new InvalidArgumentException(
                'Fetched count must equal the sum of accepted, flagged, and rejected counts.',
            );
        }
    }

    public function recordAccepted(): void
    {
        $this->accepted++;
        $this->fetched++;
    }

    public function recordFlagged(): void
    {
        $this->flagged++;
        $this->fetched++;
    }

    public function recordRejected(): void
    {
        $this->rejected++;
        $this->fetched++;
    }
}
