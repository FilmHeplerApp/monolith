<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Import;

use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\DTOs\ImportCounters;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Import\Enums\ImportRunStatus;
use App\Domain\Import\Enums\RejectionReason;
use App\Infrastructure\Persistence\Eloquent\Models\Import\ImportRejection;
use App\Infrastructure\Persistence\Eloquent\Models\Import\ImportRun;
use InvalidArgumentException;

final class EloquentImportRunRecorder implements ImportRunRecorderContract
{
    public function start(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit,
    ): ImportRunCheckpoint {
        $run = ImportRun::query()->create([
            ImportRun::FIELD_PROVIDER => $provider,
            ImportRun::FIELD_DATA_SOURCE => $dataSource,
            ImportRun::FIELD_STATUS => ImportRunStatus::Running,
            ImportRun::FIELD_LIMIT => $limit,
            ImportRun::FIELD_CHECKPOINT => 0,
            ImportRun::FIELD_FETCHED => 0,
            ImportRun::FIELD_ACCEPTED => 0,
            ImportRun::FIELD_FLAGGED => 0,
            ImportRun::FIELD_REJECTED => 0,
            ImportRun::FIELD_STARTED_AT => now(),
        ]);

        return $this->checkpoint($run);
    }

    public function resume(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit,
    ): ?ImportRunCheckpoint {
        $run = ImportRun::query()
            ->where(ImportRun::FIELD_PROVIDER, $provider)
            ->where(ImportRun::FIELD_DATA_SOURCE, $dataSource)
            ->whereIn(ImportRun::FIELD_STATUS, [ImportRunStatus::Running, ImportRunStatus::Failed])
            ->latest(ImportRun::FIELD_ID)
            ->first();

        if ($run === null) {
            return null;
        }

        if ($limit !== null && $run->limit !== $limit) {
            throw new InvalidArgumentException(
                "Resume limit [$limit] does not match import run limit [$run->limit].",
            );
        }

        $run->update([
            ImportRun::FIELD_STATUS => ImportRunStatus::Running,
            ImportRun::FIELD_FINISHED_AT => null,
            ImportRun::FIELD_ERROR_MESSAGE => null,
        ]);

        return $this->checkpoint($run);
    }

    public function progress(int $runId, ImportCounters $counters, int $checkpoint): void
    {
        $this->update($runId, $counters, $checkpoint);
    }

    public function rejection(
        int             $runId,
        string          $externalId,
        RejectionReason $reason,
        array           $context = [],
    ): void {
        ImportRejection::query()->updateOrCreate(
            [
                ImportRejection::FIELD_IMPORT_RUN_ID => $runId,
                ImportRejection::FIELD_EXTERNAL_ID => $externalId,
                ImportRejection::FIELD_REASON => $reason,
            ],
            [ImportRejection::FIELD_CONTEXT => $context === [] ? null : $context],
        );
    }

    public function complete(int $runId, ImportCounters $counters, int $checkpoint): void
    {
        $this->update($runId, $counters, $checkpoint, [
            ImportRun::FIELD_STATUS => ImportRunStatus::Completed,
            ImportRun::FIELD_FINISHED_AT => now(),
            ImportRun::FIELD_ERROR_MESSAGE => null,
        ]);
    }

    public function fail(int $runId, ImportCounters $counters, int $checkpoint, string $message): void
    {
        $this->update($runId, $counters, $checkpoint, [
            ImportRun::FIELD_STATUS => ImportRunStatus::Failed,
            ImportRun::FIELD_FINISHED_AT => now(),
            ImportRun::FIELD_ERROR_MESSAGE => $message,
        ]);
    }


    /** @param array<string, mixed> $extra */
    private function update(
        int            $runId,
        ImportCounters $counters,
        int            $checkpoint,
        array          $extra = [],
    ): void {
        ImportRun::query()->whereKey($runId)->update([
            ImportRun::FIELD_CHECKPOINT => $checkpoint,
            ImportRun::FIELD_FETCHED => $counters->fetched,
            ImportRun::FIELD_ACCEPTED => $counters->accepted,
            ImportRun::FIELD_FLAGGED => $counters->flagged,
            ImportRun::FIELD_REJECTED => $counters->rejected,
            ...$extra,
        ]);
    }

    private function checkpoint(ImportRun $run): ImportRunCheckpoint
    {
        return new ImportRunCheckpoint(
            runId: $run->getKey(),
            checkpoint: $run->checkpoint,
            counters: new ImportCounters(
                fetched: $run->fetched,
                accepted: $run->accepted,
                flagged: $run->flagged,
                rejected: $run->rejected,
            ),
            limit: $run->limit,
        );
    }
}
