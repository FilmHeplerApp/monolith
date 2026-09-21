<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Services;

use App\Domain\Import\Contracts\ImportFilterRuleContract;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Services\ImportFilterEngine;
use App\Domain\Import\ValueObjects\FilterDecision;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\CandidateParent;

final class ImportFilterEngineTest extends TestCase
{
    #[Test]
    public function accepts_when_all_rules_accept(): void
    {
        $engine = new ImportFilterEngine([
            self::ruleReturning(FilterDecision::accept()),
            self::ruleReturning(FilterDecision::accept()),
        ]);

        self::assertTrue($engine->decide(CandidateParent::create())->isAccepted());
    }

    #[Test]
    public function rejects_when_any_rule_rejects(): void
    {
        $engine = new ImportFilterEngine([
            self::ruleReturning(FilterDecision::accept()),
            self::ruleReturning(FilterDecision::reject(RejectionReason::YEAR_TOO_OLD)),
        ]);

        $decision = $engine->decide(CandidateParent::create());

        self::assertTrue($decision->isRejected());
        self::assertSame(RejectionReason::YEAR_TOO_OLD, $decision->reason);
    }

    #[Test]
    public function first_rejection_wins(): void
    {
        $engine = new ImportFilterEngine([
            self::ruleReturning(FilterDecision::reject(RejectionReason::NO_TITLE)),
            self::ruleReturning(FilterDecision::reject(RejectionReason::YEAR_TOO_OLD)),
        ]);

        self::assertSame(RejectionReason::NO_TITLE, $engine->decide(CandidateParent::create())->reason);
    }

    #[Test]
    public function reject_wins_over_flag(): void
    {
        $engine = new ImportFilterEngine([
            self::ruleReturning(FilterDecision::flag(['missing' => ['poster']])),
            self::ruleReturning(FilterDecision::reject(RejectionReason::LOW_POPULARITY)),
        ]);

        self::assertTrue($engine->decide(CandidateParent::create())->isRejected());
    }

    #[Test]
    public function flags_when_a_rule_flags_and_none_reject(): void
    {
        $engine = new ImportFilterEngine([
            self::ruleReturning(FilterDecision::accept()),
            self::ruleReturning(FilterDecision::flag(['missing' => ['poster']])),
        ]);

        $decision = $engine->decide(CandidateParent::create());

        self::assertTrue($decision->isFlagged());
        self::assertSame(['missing' => ['poster']], $decision->context);
    }

    #[Test]
    public function merges_flag_contexts_from_multiple_rules(): void
    {
        $engine = new ImportFilterEngine([
            self::ruleReturning(FilterDecision::flag(['missing' => ['description']])),
            self::ruleReturning(FilterDecision::flag(['missing' => ['poster']])),
        ]);

        $decision = $engine->decide(CandidateParent::create());

        self::assertTrue($decision->isFlagged());
        self::assertSame(['missing' => ['description', 'poster']], $decision->context);
    }

    private static function ruleReturning(FilterDecision $decision): ImportFilterRuleContract
    {
        return new readonly class($decision) implements ImportFilterRuleContract
        {
            public function __construct(private FilterDecision $decision) {}

            public function evaluate(TitleCandidate $candidate): FilterDecision
            {
                return $this->decision;
            }
        };
    }
}
