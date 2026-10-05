<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\DTOs;

use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\Enums\CandidateOutcome;
use App\Domain\Import\Enums\RejectionReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterDecisionTest extends TestCase
{
    private const array FLAG_CONTEXT = ['missing' => ['description', 'poster']];

    private const array REJECTION_CONTEXT = ['score' => 3.2, 'count' => 5];


    #[Test]
    public function accept_marks_decision_as_accepted(): void
    {
        $decision = FilterDecision::accept();

        self::assertSame(CandidateOutcome::ACCEPTED, $decision->outcome);
        self::assertTrue($decision->isAccepted());
        self::assertFalse($decision->isFlagged());
        self::assertFalse($decision->isRejected());
        self::assertNull($decision->reason);
    }

    #[Test]
    public function flag_marks_decision_as_flagged_without_reason(): void
    {
        $decision = FilterDecision::flag();

        self::assertSame(CandidateOutcome::FLAGGED, $decision->outcome);
        self::assertTrue($decision->isFlagged());
        self::assertFalse($decision->isAccepted());
        self::assertFalse($decision->isRejected());
        self::assertNull($decision->reason);
        self::assertSame([], $decision->context);
    }

    #[Test]
    public function flag_carries_context(): void
    {
        $decision = FilterDecision::flag(self::FLAG_CONTEXT);

        self::assertSame(self::FLAG_CONTEXT, $decision->context);
    }

    #[Test]
    public function reject_marks_decision_as_rejected_with_reason(): void
    {
        $decision = FilterDecision::reject(RejectionReason::YEAR_TOO_OLD);

        self::assertSame(CandidateOutcome::REJECTED, $decision->outcome);
        self::assertTrue($decision->isRejected());
        self::assertFalse($decision->isAccepted());
        self::assertFalse($decision->isFlagged());
        self::assertSame(RejectionReason::YEAR_TOO_OLD, $decision->reason);
    }

    #[Test]
    public function reject_carries_context(): void
    {
        $decision = FilterDecision::reject(RejectionReason::LOW_POPULARITY, self::REJECTION_CONTEXT);

        self::assertSame(RejectionReason::LOW_POPULARITY, $decision->reason);
        self::assertSame(self::REJECTION_CONTEXT, $decision->context);
    }
}
