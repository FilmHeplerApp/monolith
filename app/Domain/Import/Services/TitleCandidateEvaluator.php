<?php

declare(strict_types=1);

namespace App\Domain\Import\Services;

use App\Domain\Import\Contracts\ImportFilterRuleContract;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;

final readonly class TitleCandidateEvaluator
{
    /** @param list<ImportFilterRuleContract> $rules */
    public function __construct(private array $rules) {}

    public function decide(TitleCandidate $candidate): FilterDecision
    {
        $hasFlag = false;
        $flagContext = [];

        foreach ($this->rules as $rule) {
            $decision = $rule->evaluate($candidate);

            if ($decision->isRejected()) {
                return $decision;
            }

            if ($decision->isFlagged()) {
                $hasFlag = true;
                $flagContext = $this->mergeContext($flagContext, $decision->context ?? []);
            }
        }

        return $hasFlag
            ? FilterDecision::flag($flagContext)
            : FilterDecision::accept();
    }


    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $add
     * @return array<string, mixed>
     */
    private function mergeContext(array $base, array $add): array
    {
        foreach ($add as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = array_values(array_unique([...$base[$key], ...$value]));
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
