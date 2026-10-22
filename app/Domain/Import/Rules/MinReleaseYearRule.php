<?php

declare(strict_types=1);

namespace App\Domain\Import\Rules;

use App\Domain\Import\Contracts\ImportFilterRuleContract;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\RejectionReason;

final readonly class MinReleaseYearRule implements ImportFilterRuleContract
{
    public function __construct(private int $minYear) {}

    public function evaluate(TitleCandidate $candidate): FilterDecision
    {
        if ($candidate->releaseYear === null) {
            return FilterDecision::accept();
        }

        return $candidate->releaseYear < $this->minYear
            ? FilterDecision::reject(RejectionReason::YEAR_TOO_OLD, ['year' => $candidate->releaseYear])
            : FilterDecision::accept();
    }
}
