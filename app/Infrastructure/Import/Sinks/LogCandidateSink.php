<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Sinks;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class LogCandidateSink implements CandidateSinkContract
{
    private array $pending = [];


    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        $this->pending[] = [
            'source' => $candidate->source,
            'external_id' => $candidate->externalId,
            'outcome' => $decision->outcome->value,
            'context' => $decision->context,
        ];
    }

    public function flush(): void
    {
        $pending = $this->pending;
        DB::afterCommit(static function () use ($pending): void {
            foreach ($pending as $context) {
                Log::info('Import candidate accepted.', $context);
            }
        });
        $this->pending = [];
    }

    public function finish(): void
    {
        $this->flush();
    }

    public function abort(): void
    {
        $this->pending = [];
    }
}
