<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Rules;

use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Rules\MinReleaseYearRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\CandidateParent;

final class MinReleaseYearRuleTest extends TestCase
{
    #[Test]
    public function accepts_recent_year(): void
    {
        self::assertTrue(new MinReleaseYearRule(1990)->evaluate(CandidateParent::create(releaseYear: 2009))->isAccepted());
    }

    #[Test]
    public function rejects_too_old(): void
    {
        $decision = new MinReleaseYearRule(1990)->evaluate(CandidateParent::create(releaseYear: 1980));

        self::assertTrue($decision->isRejected());
        self::assertSame(RejectionReason::YEAR_TOO_OLD, $decision->reason);
    }

    #[Test]
    public function accepts_unknown_year(): void
    {
        self::assertTrue(new MinReleaseYearRule(1990)->evaluate(CandidateParent::create(releaseYear: null))->isAccepted());
    }
}
