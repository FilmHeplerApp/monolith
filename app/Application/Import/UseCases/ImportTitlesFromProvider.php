<?php

declare(strict_types=1);

namespace App\Application\Import\UseCases;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Contracts\ProviderSnapshotContract;
use App\Application\Import\DTOs\ImportResult;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderTitleMappingException;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Domain\Import\Services\TitleCandidateEvaluator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class ImportTitlesFromProvider
{
    public function __construct(
        private ProviderClientFactoryContract  $clients,
        private ProviderTitleToCandidateMapper $mapper,
        private TitleCandidateEvaluator        $filter,
        private ImportRunRecorderContract      $recorder,
        private CandidateSinkContract          $sink,
        private ImportRunLockContract          $lock,
        private int                            $checkpointEvery = 50,
    ) {}

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

            return $this->process($provider, $dataSource, $run, $resumed);
        });
    }


    private function process(
        ProviderSource      $provider,
        ProviderDataSource  $dataSource,
        ImportRunCheckpoint $run,
        bool                $resumed,
    ): ImportResult {
        $committedCounters = clone $run->counters;
        $counters = clone $run->counters;
        $committedCheckpoint = $checkpoint = $run->checkpoint;
        $pendingIds = [];
        $client = null;

        try {
            $client = $this->clients->make($provider, $dataSource);
            $snapshot = $client instanceof ProviderSnapshotContract;

            if ($snapshot) {
                $this->recorder->bindSnapshot($run->runId, $client->snapshotId());
            }

            // API positions can shift. Replay from the beginning and skip only committed identities.
            $seen = array_fill_keys($snapshot ? [] : $this->recorder->processedIds($run->runId), true);
            $remaining = $run->limit === null ? null : max(0, $run->limit - $checkpoint);

            if ($remaining !== 0) {
                foreach ($client->fetchTitles($snapshot ? $remaining : null, $snapshot ? $checkpoint : 0) as $providerTitle) {
                    if (!$snapshot && isset($seen[$providerTitle->externalId])) {
                        continue;
                    }

                    try {
                        $candidate = $this->mapper->map($providerTitle);
                        $decision = $this->filter->decide($candidate);

                        if ($decision->isRejected()) {
                            $this->recorder->rejection($run->runId, $providerTitle->externalId, $decision->reason, $decision->context ?? []);
                            $counters->recordRejected();
                        } else {
                            $this->sink->consume($candidate, $decision);
                            $decision->isFlagged() ? $counters->recordFlagged() : $counters->recordAccepted();
                        }
                    } catch (ProviderTitleMappingException $exception) {
                        $this->recorder->rejection($run->runId, $providerTitle->externalId, $exception->reason, $exception->context);
                        $counters->recordRejected();
                    }

                    $pendingIds[] = $providerTitle->externalId;
                    $seen[$providerTitle->externalId] = true;
                    $checkpoint++;

                    if ($run->limit !== null && $checkpoint >= $run->limit) {
                        break;
                    }

                    if (count($pendingIds) >= max(1, $this->checkpointEvery)) {
                        $this->sink->flush();
                        $this->recorder->progress($run->runId, $counters, $checkpoint, $pendingIds);
                        $committedCounters = clone $counters;
                        $committedCheckpoint = $checkpoint;
                        $pendingIds = [];
                    }
                }
            }

            $this->sink->finish();
            $this->recorder->complete($run->runId, $counters, $checkpoint, $pendingIds);
        } catch (Throwable $exception) {
            try {
                $this->sink->abort();
            } catch (Throwable $abortError) {
                Log::error('Failed to discard pending import candidates.', ['exception' => $abortError]);
            }

            try {
                $this->recorder->fail($run->runId, $committedCounters, $committedCheckpoint, $exception->getMessage());
            } catch (Throwable $recordError) {
                Log::error('Failed to record the import failure.', ['exception' => $recordError, 'original_exception' => $exception]);
            }

            throw $exception;
        } finally {
            if ($client instanceof ProviderSnapshotContract) {
                $client->close();
            }
        }

        return new ImportResult($run->runId, $counters, $checkpoint, $resumed);
    }
}
