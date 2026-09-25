<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\ValueObjects\FilterDecision;

interface CandidateSinkContract
{
    public function consume(TitleCandidate $candidate, FilterDecision $decision): void;

    public function finish(): void;
}
