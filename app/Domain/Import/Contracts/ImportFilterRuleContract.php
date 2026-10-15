<?php

declare(strict_types=1);

namespace App\Domain\Import\Contracts;

use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;

interface ImportFilterRuleContract
{
    public function evaluate(TitleCandidate $candidate): FilterDecision;
}
