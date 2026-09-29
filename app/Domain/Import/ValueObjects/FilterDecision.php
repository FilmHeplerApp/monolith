<?php

declare(strict_types=1);

namespace App\Domain\Import\ValueObjects;

use App\Domain\Import\Enums\CandidateOutcome;
use App\Domain\Import\Enums\RejectionReason;

final readonly class FilterDecision
{
    private function __construct(
        public CandidateOutcome $outcome,
        public ?RejectionReason $reason,
        public ?array           $context = null,
    ) {
    }

    public static function accept(): self
    {
        return new self(CandidateOutcome::ACCEPTED, null);
    }

    public static function flag(?array $context = []): self
    {
        return new self(CandidateOutcome::FLAGGED, null, $context);
    }

    public static function reject(RejectionReason $reason, ?array $context = []): self
    {
        return new self(CandidateOutcome::REJECTED, $reason, $context);
    }

    public function isAccepted(): bool
    {
        return $this->outcome === CandidateOutcome::ACCEPTED;
    }

    public function isFlagged(): bool
    {
        return $this->outcome === CandidateOutcome::FLAGGED;
    }

    public function isRejected(): bool
    {
        return $this->outcome === CandidateOutcome::REJECTED;
    }
}
