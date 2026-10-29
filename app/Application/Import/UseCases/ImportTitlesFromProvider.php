<?php

declare(strict_types=1);

namespace App\Application\Import\UseCases;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\DTOs\ImportBatch;
use App\Application\Import\DTOs\ImportRejectionData;
use App\Application\Import\DTOs\ImportResult;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderTitleMappingException;
use App\Application\Import\Services\ImportTitleStreamFactory;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Domain\Import\Services\TitleCandidateEvaluator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class ImportTitlesFromProvider
{
    public function __construct(
        private ImportTitleStreamFactory       $streams,
        private ProviderTitleToCandidateMapper $mapper,
        private TitleCandidateEvaluator        $filter,
        private ImportRunRecorderContract      $recorder,
        private CandidateSinkContract          $sink,
        private ImportRunLockContract          $lock,
        private int                            $checkpointEvery = 50,
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

        return $this->lock->synchronized($provider, $dataSource, function () use ($provider, $dataSource, $limit, $resume): ImportResult {
            $run = $resume ? $this->recorder->resume($provider, $dataSource, $limit) : null;
            $resumed = $run !== null;
            $run ??= $this->recorder->start($provider, $dataSource, $limit);

            if ($run->completed) {
                return new ImportResult($run->runId, $run->counters, $run->checkpoint, true);
            }

            return $this->process($provider, $dataSource, $run, $resumed);
        });
    }


    private function process(
        ProviderSource      $provider,
        ProviderDataSource  $dataSource,
        ImportRunCheckpoint $run,
        bool                $resumed,
    ): ImportResult {
        $counters = clone $run->counters;
        $checkpoint = $run->checkpoint;
        $pendingIds = [];
        $rejections = [];
        $stream = null;

        try {
            $stream = $this->streams->create($provider, $dataSource, $run);

            foreach ($stream->titles() as $providerTitle) {
                try {
                    $candidate = $this->mapper->map($providerTitle);
                    $decision = $this->filter->decide($candidate);

                    if ($decision->isRejected()) {
                        $rejections[] = new ImportRejectionData($providerTitle->externalId, $decision->reason, $decision->context ?? []);
                        $counters->recordRejected();
                    } else {
                        $this->sink->consume($candidate, $decision);
                        $decision->isFlagged() ? $counters->recordFlagged() : $counters->recordAccepted();
                    }
                } catch (ProviderTitleMappingException $exception) {
                    $rejections[] = new ImportRejectionData($providerTitle->externalId, $exception->reason, $exception->context);
                    $counters->recordRejected();
                }

                $pendingIds[] = $providerTitle->externalId;
                $checkpoint++;

                if (count($pendingIds) >= $this->checkpointEvery) {
                    $run = $this->commit($run, new ImportBatch(clone $counters, $checkpoint, $pendingIds, $rejections));
                    $pendingIds = [];
                    $rejections = [];
                }
            }

            $run = $this->commit($run, new ImportBatch(clone $counters, $checkpoint, $pendingIds, $rejections), complete: true);
        } catch (Throwable $exception) {
            try {
                $this->sink->abort();
            } catch (Throwable $abortError) {
                Log::error('Failed to discard pending import candidates.', ['exception' => $abortError]);
            }

            try {
                $this->lock->assertOwned();
                $this->recorder->fail($run, $exception->getMessage());
            } catch (Throwable $recordError) {
                Log::error('Failed to record the import failure.', ['exception' => $recordError, 'original_exception' => $exception]);
            }

            throw $exception;
        } finally {
            $stream?->close();
        }

        return new ImportResult($run->runId, $run->counters, $run->checkpoint, $resumed);
    }

    private function commit(ImportRunCheckpoint $run, ImportBatch $batch, bool $complete = false): ImportRunCheckpoint
    {
        $this->lock->assertOwned();

        return $this->recorder->commit($run, $batch, function () use ($complete): void {
            $this->lock->assertOwned();
            $complete ? $this->sink->finish() : $this->sink->flush();
            $this->lock->assertOwned();
        }, $complete);
    }
}
