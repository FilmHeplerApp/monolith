<?php

declare(strict_types=1);

namespace App\Domain\Import\Rules;

use App\Domain\Import\Contracts\ImportFilterRuleContract;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\ValueObjects\FilterDecision;

final readonly class MinProviderScoreRule implements ImportFilterRuleContract
{
    public function __construct(
        private float $minScore,
        private int $minScoreCount,
    ) {}

    public function evaluate(TitleCandidate $candidate): FilterDecision
    {
        if ($candidate->providerScore === null && $candidate->providerScoreCount === null) {
            return FilterDecision::accept();
        }

        $scoreOk = $candidate->providerScore !== null && $candidate->providerScore >= $this->minScore;
        $countOk = $candidate->providerScoreCount !== null && $candidate->providerScoreCount >= $this->minScoreCount;

        if ($scoreOk || $countOk) {
            return FilterDecision::accept();
        }

        return FilterDecision::reject(RejectionReason::LOW_POPULARITY, [
            'score' => $candidate->providerScore,
            'count' => $candidate->providerScoreCount,
        ]);
    }
}
