<?php

declare(strict_types=1);

namespace App\Domain\Import\Rules;

use App\Domain\Import\Contracts\ImportFilterRuleContract;
use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\ValueObjects\FilterDecision;
use App\Domain\Import\ValueObjects\TitleCandidate;

final readonly class HasAnyTitleRule implements ImportFilterRuleContract
{
    public function evaluate(TitleCandidate $candidate): FilterDecision
    {
        return $candidate->title === null
            ? FilterDecision::reject(RejectionReason::NO_TITLE)
            : FilterDecision::accept();
    }
}
