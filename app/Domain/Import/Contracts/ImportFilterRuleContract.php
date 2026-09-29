<?php

declare(strict_types=1);

namespace App\Domain\Import\Contracts;

use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\ValueObjects\FilterDecision;

interface ImportFilterRuleContract
{
    public function evaluate(TitleCandidate $candidate): FilterDecision;
}
