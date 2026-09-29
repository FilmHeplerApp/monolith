<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Rules;

use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Rules\MinProviderScoreRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\CandidateParent;

final class MinProviderScoreRuleTest extends TestCase
{
    #[Test]
    public function accepts_high_score(): void
    {
        self::assertTrue(new MinProviderScoreRule(6.0, 1000)
            ->evaluate(CandidateParent::create(providerScore: 9.1, providerScoreCount: 50000))->isAccepted());
    }

    #[Test]
    public function accepts_when_count_high_even_if_score_low(): void
    {
        self::assertTrue(new MinProviderScoreRule(6.0, 1000)
            ->evaluate(CandidateParent::create(providerScore: 5.0, providerScoreCount: 50000))->isAccepted());
    }

    #[Test]
    public function rejects_low_score_and_low_count(): void
    {
        $decision = new MinProviderScoreRule(6.0, 1000)
            ->evaluate(CandidateParent::create(providerScore: 3.0, providerScoreCount: 10));

        self::assertTrue($decision->isRejected());
        self::assertSame(RejectionReason::LOW_POPULARITY, $decision->reason);
    }

    #[Test]
    public function accepts_when_no_popularity_data(): void
    {
        self::assertTrue(new MinProviderScoreRule(6.0, 1000)
            ->evaluate(CandidateParent::create(providerScore: null, providerScoreCount: null))->isAccepted());
    }
}
