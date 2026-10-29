<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;

interface CandidateSinkContract
{
    public function consume(TitleCandidate $candidate, FilterDecision $decision): void;

    public function flush(): void;

    public function finish(): void;

    public function abort(): void;
}
