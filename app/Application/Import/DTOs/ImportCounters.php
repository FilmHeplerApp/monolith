<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use InvalidArgumentException;

/**
 * Immutable run totals: batches and checkpoints keep the snapshot they were built with.
 */
final readonly class ImportCounters
{
    public function __construct(
        public int $fetched = 0,
        public int $accepted = 0,
        public int $flagged = 0,
        public int $rejected = 0,
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

    public function withAccepted(): self
    {
        return new self(
            $this->fetched + 1,
            $this->accepted + 1,
            $this->flagged,
            $this->rejected
        );
    }

    public function withFlagged(): self
    {
        return new self(
            $this->fetched + 1,
            $this->accepted,
            $this->flagged + 1,
            $this->rejected
        );
    }

    public function withRejected(): self
    {
        return new self(
            $this->fetched + 1,
            $this->accepted,
            $this->flagged,
            $this->rejected + 1
        );
    }
}
