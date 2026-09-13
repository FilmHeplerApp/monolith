<?php

declare(strict_types=1);

namespace App\Domain\Import\Contracts;

use App\Domain\Import\ValueObjects\FilterDecision;
use App\Domain\Import\ValueObjects\TitleCandidate;

interface ImportFilterRuleContract
{
    public function evaluate(TitleCandidate $candidate): FilterDecision;
}
