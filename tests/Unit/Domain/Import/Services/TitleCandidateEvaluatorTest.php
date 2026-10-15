<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Services;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Import\Contracts\ImportFilterRuleContract;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Rules\AllowedContentTypeRule;
use App\Domain\Import\Rules\CompletenessRule;
use App\Domain\Import\Rules\ExcludedTitleFormatRule;
use App\Domain\Import\Rules\HasAnyTitleRule;
use App\Domain\Import\Rules\MinReleaseYearRule;
use App\Domain\Import\Services\TitleCandidateEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\TitleCandidateFactory;

final class TitleCandidateEvaluatorTest extends TestCase
{
    #[Test]
    #[DataProvider('providerRatingData')]
    public function provider_rating_does_not_reject_or_flag_an_otherwise_complete_candidate(
        ?float $score,
        ?int   $count,
    ): void {
        $evaluator = new TitleCandidateEvaluator([
            new HasAnyTitleRule,
            new AllowedContentTypeRule([TitleContentType::ANIME]),
            new ExcludedTitleFormatRule,
            new MinReleaseYearRule(1990),
            new CompletenessRule,
        ]);

        $decision = $evaluator->decide(TitleCandidateFactory::create(providerScore: $score, providerScoreCount: $count));

        self::assertTrue($decision->isAccepted());
        self::assertNull($decision->reason);
    }

    public static function providerRatingData(): array
    {
        return [
            'unknown score and count' => [null, null],
            'only high score' => [9.1, null],
            'only low score' => [3.0, null],
            'only high count' => [null, 50000],
            'only low count' => [null, 10],
            'zero score without count' => [0.0, null],
            'zero count without score' => [null, 0],
            'both zero' => [0.0, 0],
            'low score and low count' => [3.0, 10],
            'low score and high count' => [3.0, 50000],
            'high score and zero count' => [9.1, 0],
            'high score and high count' => [9.1, 50000],
        ];
    }

    #[Test]
    public function excluded_format_is_rejected_even_when_the_candidate_is_incomplete(): void
    {
        $evaluator = new TitleCandidateEvaluator([
            new CompletenessRule,
            new ExcludedTitleFormatRule,
        ]);

        $decision = $evaluator->decide(TitleCandidateFactory::create(format: TitleFormat::PV, posterUrl: null));

        self::assertTrue($decision->isRejected());
        self::assertSame(RejectionReason::UNSUPPORTED_FORMAT, $decision->reason);
        self::assertSame(['format' => 'pv'], $decision->context);
    }

    #[Test]
    public function accepts_when_all_rules_accept(): void
    {
        $evaluator = new TitleCandidateEvaluator([
            self::ruleReturning(FilterDecision::accept()),
            self::ruleReturning(FilterDecision::accept()),
        ]);

        self::assertTrue($evaluator->decide(TitleCandidateFactory::create())->isAccepted());
    }

    #[Test]
    public function rejects_when_any_rule_rejects(): void
    {
        $evaluator = new TitleCandidateEvaluator([
            self::ruleReturning(FilterDecision::accept()),
            self::ruleReturning(FilterDecision::reject(RejectionReason::YEAR_TOO_OLD)),
        ]);

        $decision = $evaluator->decide(TitleCandidateFactory::create());

        self::assertTrue($decision->isRejected());
        self::assertSame(RejectionReason::YEAR_TOO_OLD, $decision->reason);
    }

    #[Test]
    public function first_rejection_wins(): void
    {
        $evaluator = new TitleCandidateEvaluator([
            self::ruleReturning(FilterDecision::reject(RejectionReason::NO_TITLE)),
            self::ruleReturning(FilterDecision::reject(RejectionReason::YEAR_TOO_OLD)),
        ]);

        self::assertSame(RejectionReason::NO_TITLE, $evaluator->decide(TitleCandidateFactory::create())->reason);
    }

    #[Test]
    public function reject_wins_over_flag(): void
    {
        $evaluator = new TitleCandidateEvaluator([
            self::ruleReturning(FilterDecision::flag(['missing' => ['poster']])),
            self::ruleReturning(FilterDecision::reject(RejectionReason::UNSUPPORTED_FORMAT)),
        ]);

        self::assertTrue($evaluator->decide(TitleCandidateFactory::create())->isRejected());
    }

    #[Test]
    public function flags_when_a_rule_flags_and_none_reject(): void
    {
        $evaluator = new TitleCandidateEvaluator([
            self::ruleReturning(FilterDecision::accept()),
            self::ruleReturning(FilterDecision::flag(['missing' => ['poster']])),
        ]);

        $decision = $evaluator->decide(TitleCandidateFactory::create());

        self::assertTrue($decision->isFlagged());
        self::assertSame(['missing' => ['poster']], $decision->context);
    }

    #[Test]
    #[DataProvider('emptyFlagContexts')]
    public function preserves_a_flag_without_context(?array $context): void
    {
        $evaluator = new TitleCandidateEvaluator([
            self::ruleReturning(FilterDecision::flag($context)),
            self::ruleReturning(FilterDecision::accept()),
        ]);

        $decision = $evaluator->decide(TitleCandidateFactory::create());

        self::assertTrue($decision->isFlagged());
        self::assertSame([], $decision->context);
    }

    #[Test]
    public function rejection_wins_over_a_flag_without_context(): void
    {
        $evaluator = new TitleCandidateEvaluator([
            self::ruleReturning(FilterDecision::flag()),
            self::ruleReturning(FilterDecision::reject(RejectionReason::UNSUPPORTED_FORMAT)),
        ]);

        self::assertTrue($evaluator->decide(TitleCandidateFactory::create())->isRejected());
    }

    public static function emptyFlagContexts(): array
    {
        return [
            'empty array' => [[]],
            'null' => [null],
        ];
    }

    #[Test]
    public function merges_flag_contexts_from_multiple_rules(): void
    {
        $evaluator = new TitleCandidateEvaluator([
            self::ruleReturning(FilterDecision::flag(['missing' => ['description']])),
            self::ruleReturning(FilterDecision::flag(['missing' => ['poster']])),
        ]);

        $decision = $evaluator->decide(TitleCandidateFactory::create());

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
