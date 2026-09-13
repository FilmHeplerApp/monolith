<?php

declare(strict_types=1);

namespace App\Domain\Import\Rules;

use App\Domain\Import\Contracts\ImportFilterRuleContract;
use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\ValueObjects\FilterDecision;
use App\Domain\Import\ValueObjects\TitleCandidate;

final readonly class MinReleaseYearRule implements ImportFilterRuleContract
{
    public function __construct(private int $minYear) {}

    public function evaluate(TitleCandidate $candidate): FilterDecision
    {
        if ($candidate->releaseYear !== null && $candidate->releaseYear < $this->minYear) {
            return FilterDecision::reject(RejectionReason::YEAR_TOO_OLD, ['year' => $candidate->releaseYear]);
        }

        return FilterDecision::accept();
    }
}
