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


    private static function emit(array $pending): void
    {
        foreach ($pending as $context) {
            try {
                Log::info('Import candidate accepted.', $context);
            } catch (Throwable $exception) {
                try {
                    @error_log('Import candidate logging failed: '.json_encode([
                        'source' => $context['source'],
                        'external_id' => $context['external_id'],
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
                } catch (Throwable) {
                }
            }
        }
    }
}
