<?php

declare(strict_types=1);

namespace App\Application\Import\UseCases;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ProviderImportContextFactoryContract;
use App\Application\Import\DTOs\ImportBatch;
use App\Application\Import\DTOs\ImportRejectionData;
use App\Application\Import\DTOs\ImportResult;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Enums\ImportRunMode;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderTitleMappingException;
use App\Application\Import\Services\ImportTitleStreamFactory;
use App\Application\Import\Services\ProviderImportContext;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Domain\Import\Services\TitleCandidateEvaluator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class ImportTitlesFromProvider
{
    public function __construct(
        private ProviderImportContextFactoryContract $contexts,
        private ImportTitleStreamFactory             $streams,
        private ProviderTitleToCandidateMapper       $candidateMapper,
        private TitleCandidateEvaluator              $filter,
        private ImportRunRecorderContract            $recorder,
        private CandidateSinkContract                $sink,
        private ImportRunLockContract                $lock,
        private int                                  $checkpointEvery = 50,
    ) {
        if ($checkpointEvery < 1) {
            throw new InvalidArgumentException('Checkpoint interval must be positive.');
        }
    }

    public function execute(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit = null,
        bool               $resume = false,
    ): ImportResult {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Import limit must be positive or null.');
        }

        $context = $this->contexts->create($provider, $dataSource);
        if ($context->source() !== $provider) {
            throw new RuntimeException('Import context does not match the requested provider.');
        }

        return $this->lock->synchronized($provider, $dataSource, function () use ($context, $provider, $dataSource, $limit, $resume): ImportResult {
            if (!$resume) {
                return $this->process(
                    $context,
                    $this->recorder->start($provider, $dataSource, $limit, $this->lock->assertOwned(...)),
                    ImportRunMode::New,
                );
            }

            $run = $this->recorder->resume($provider, $dataSource, $limit, $this->lock->assertOwned(...))
                ?? throw new RuntimeException('There is no import run to resume. Run without --resume.');

            if ($run->completed) {
                return new ImportResult($run->runId, $run->counters, $run->checkpoint, ImportRunMode::AlreadyCompleted);
            }

            return $this->process($context, $run, ImportRunMode::Resumed);
        });
    }


    private function process(
        ProviderImportContext $context,
        ImportRunCheckpoint   $run,
        ImportRunMode         $mode,
    ): ImportResult {
        $counters = $run->counters;
        $checkpoint = $run->checkpoint;
        $pendingIds = [];
        $rejections = [];
        $stream = null;

        try {
            $stream = $this->streams->create($context, $run);

            foreach ($stream->titles() as $record) {
                try {
                    $candidate = $this->candidateMapper->map($context->interpret($record));
                    $decision = $this->filter->decide($candidate);

                    if ($decision->isRejected()) {
                        $rejections[] = new ImportRejectionData($record->externalId, $decision->reason, $decision->context ?? []);
                        $counters = $counters->withRejected();
                    } else {
                        $this->sink->consume($candidate, $decision);
                        $counters = $decision->isFlagged() ? $counters->withFlagged() : $counters->withAccepted();
                    }
                } catch (ProviderTitleMappingException $exception) {
                    $rejections[] = new ImportRejectionData($record->externalId, $exception->reason, $exception->context);
                    $counters = $counters->withRejected();
                }

                $pendingIds[] = $record->externalId;
                $checkpoint++;

                if (count($pendingIds) >= $this->checkpointEvery) {
                    $run = $this->commit($run, new ImportBatch($counters, $checkpoint, $pendingIds, $rejections));
                    $pendingIds = [];
                    $rejections = [];
                }
            }

            $run = $this->commit($run, new ImportBatch($counters, $checkpoint, $pendingIds, $rejections), complete: true);
        } catch (Throwable $exception) {
            try {
                $this->sink->abort();
            } catch (Throwable $abortError) {
                $this->logQuietly('Failed to discard pending import candidates.', ['exception' => $abortError]);
            }

            try {
                $this->lock->assertOwned();
                $this->recorder->fail($run, $exception->getMessage());
            } catch (Throwable $recordError) {
                $this->logQuietly('Failed to record the import failure.', ['exception' => $recordError, 'original_exception' => $exception]);
            }

            throw $exception;
        } finally {
            $stream?->close();
        }

        return new ImportResult($run->runId, $run->counters, $run->checkpoint, $mode);
    }

    private function logQuietly(string $message, array $context): void
    {
        try {
            Log::error($message, $context);
        } catch (Throwable) {
        }
    }

    private function commit(ImportRunCheckpoint $run, ImportBatch $batch, bool $complete = false): ImportRunCheckpoint
    {
        return $this->recorder->commit($run, $batch, function () use ($complete): void {
            $this->lock->assertOwned();
            $complete ? $this->sink->finish() : $this->sink->flush();
        }, $complete);
    }
}
