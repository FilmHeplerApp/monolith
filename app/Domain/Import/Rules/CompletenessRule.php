<?php

declare(strict_types=1);

namespace App\Domain\Import\Rules;

use App\Domain\Import\Contracts\ImportFilterRuleContract;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\ValueObjects\FilterDecision;

final readonly class CompletenessRule implements ImportFilterRuleContract
{
    public function evaluate(TitleCandidate $candidate): FilterDecision
    {
        $missing = [];

        if ($candidate->description === null) {
            $missing[] = 'description';
        }

        if ($candidate->posterUrl === null || trim($candidate->posterUrl) === '') {
            $missing[] = 'poster';
        }

        return $missing === []
            ? FilterDecision::accept()
            : FilterDecision::flag(['missing' => $missing]);
    }
}
