<?php

declare(strict_types=1);

namespace App\Application\Import\UseCases;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\DTOs\ImportResult;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderTitleMappingException;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Domain\Import\Services\ImportFilterEngine;
use InvalidArgumentException;
use Throwable;

final readonly class ImportTitlesFromProvider
{
    public function __construct(
        private ProviderClientFactoryContract  $clients,
        private ProviderTitleToCandidateMapper $mapper,
        private ImportFilterEngine             $filter,
        private ImportRunRecorderContract      $recorder,
        private CandidateSinkContract          $sink,
        private int                            $checkpointEvery = 50,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit = null,
        bool               $resume = false,
    ): ImportResult {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Import limit must be positive or null.');
        }

        $resumed = false;
        $run = null;

        if ($resume) {
            $run = $this->recorder->resume($provider, $dataSource, $limit);
            $resumed = $run !== null;
        }

        $run ??= $this->recorder->start($provider, $dataSource, $limit);

        return $this->process($provider, $dataSource, $run, $resumed);
    }


    /**
     * @throws Throwable
     */
    private function process(
        ProviderSource      $provider,
        ProviderDataSource  $dataSource,
        ImportRunCheckpoint $run,
        bool                $resumed,
    ): ImportResult {
        $counters = $run->counters;
        $checkpoint = $run->checkpoint;
        $remaining = $run->limit === null ? null : max(0, $run->limit - $checkpoint);

        try {
            $client = $this->clients->make($provider, $dataSource);

            if ($remaining !== 0) {
                foreach ($client->fetchTitles($remaining, $checkpoint) as $providerTitle) {
                    try {
                        $candidate = $this->mapper->map($providerTitle);
                        $decision = $this->filter->decide($candidate);

                        if ($decision->isRejected()) {
                            $this->recorder->rejection(
                                $run->runId,
                                $providerTitle->externalId,
                                $decision->reason,
                                $decision->context ?? [],
                            );
                            $counters->recordRejected();
                        } else {
                            $this->sink->consume($candidate, $decision);

                            if ($decision->isFlagged()) {
                                $counters->recordFlagged();
                            } else {
                                $counters->recordAccepted();
                            }
                        }
                    } catch (ProviderTitleMappingException $exception) {
                        $this->recorder->rejection(
                            $run->runId,
                            $providerTitle->externalId,
                            $exception->reason,
                            $exception->context,
                        );
                        $counters->recordRejected();
                    }

                    $checkpoint++;

                    if ($checkpoint % max(1, $this->checkpointEvery) === 0) {
                        $this->recorder->progress($run->runId, $counters, $checkpoint);
                    }
                }
            }

            $this->sink->finish();
            $this->recorder->complete($run->runId, $counters, $checkpoint);
        } catch (Throwable $exception) {
            $this->recorder->fail($run->runId, $counters, $checkpoint, $exception->getMessage());

            throw $exception;
        }

        return new ImportResult($run->runId, $counters, $checkpoint, $resumed);
    }
}
