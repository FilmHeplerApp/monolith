<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Sinks;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        DB::afterCommit(static fn () => self::emit($pending));
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


    /**
     * Runs after the batch transaction has committed: an exception here would escape
     * DB::transaction() and make a committed batch look failed, so it never leaves.
     */
    private static function emit(array $pending): void
    {
        foreach ($pending as $index => $context) {
            try {
                Log::info('Import candidate accepted.', $context);
            } catch (Throwable $exception) {
                // The logger is likely down as a whole: stop this batch and try one guarded notice.
                try {
                    Log::warning('Import candidate diagnostics were not emitted.', [
                        'skipped' => count($pending) - $index,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                } catch (Throwable) {
                }

                return;
            }
        }
    }
}
