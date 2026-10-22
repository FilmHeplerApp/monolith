<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Sinks;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use Illuminate\Support\Facades\Log;

final class LogCandidateSink implements CandidateSinkContract
{
    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        Log::info('Import candidate accepted.', [
            'source' => $candidate->source,
            'external_id' => $candidate->externalId,
            'outcome' => $decision->outcome->value,
            'context' => $decision->context,
        ]);
    }

    public function flush(): void {}

    public function finish(): void {}

    public function abort(): void {}
}
