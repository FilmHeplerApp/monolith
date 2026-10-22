<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;

interface CandidateSinkContract
{
    public function consume(TitleCandidate $candidate, FilterDecision $decision): void;

    /** Commit all consumed candidates. On failure the caller replays from its last committed checkpoint. */
    public function flush(): void;

    public function finish(): void;

    /** Discard uncommitted candidates after a failed run. Must preserve already committed output. */
    public function abort(): void;
}
