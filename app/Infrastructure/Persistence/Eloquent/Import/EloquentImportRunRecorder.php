<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Import;

use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\DTOs\ImportBatch;
use App\Application\Import\DTOs\ImportCounters;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Import\Enums\ImportRunStatus;
use App\Infrastructure\Persistence\Eloquent\Models\Import\ImportRejection;
use App\Infrastructure\Persistence\Eloquent\Models\Import\ImportRun;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class EloquentImportRunRecorder implements ImportRunRecorderContract
{
    public function start(ProviderSource $provider, ProviderDataSource $dataSource, ?int $limit): ImportRunCheckpoint
    {
        $run = ImportRun::query()->create([
            ImportRun::FIELD_PROVIDER => $provider,
            ImportRun::FIELD_DATA_SOURCE => $dataSource,
            ImportRun::FIELD_STATUS => ImportRunStatus::Running,
            ImportRun::FIELD_OWNER_TOKEN => bin2hex(random_bytes(16)),
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


    /**
     * @throws Throwable
     */
    public function resume(ProviderSource $provider, ProviderDataSource $dataSource, ?int $limit): ?ImportRunCheckpoint
    {
        return DB::transaction(function () use ($provider, $dataSource, $limit): ?ImportRunCheckpoint {
            $run = ImportRun::query()
                ->where(ImportRun::FIELD_PROVIDER, $provider)
                ->where(ImportRun::FIELD_DATA_SOURCE, $dataSource)
                ->latest(ImportRun::FIELD_ID)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                return null;
            }

            if ($limit !== null && $run->limit !== $limit) {
                throw new InvalidArgumentException("Resume limit [$limit] does not match import run limit [$run->limit].");
            }

            if ($run->checkpoint > 0 && count($this->processedIds($run->getKey())) !== $run->checkpoint) {
                throw new RuntimeException('Legacy import checkpoint cannot be resumed safely. Start without --resume.');
            }

            ImportRejection::query()->where(ImportRejection::FIELD_IMPORT_RUN_ID, $run->id)
                ->whereNotIn(ImportRejection::FIELD_EXTERNAL_ID, DB::table('import_processed_titles')
                    ->where('import_run_id', $run->id)->select('external_id'))
                ->delete();

            if (ImportRejection::query()->where(ImportRejection::FIELD_IMPORT_RUN_ID, $run->id)->count() !== $run->rejected) {
                throw new RuntimeException('Legacy rejection report is inconsistent. Start without --resume.');
            }

            if ($run->status === ImportRunStatus::Completed) {
                return $this->checkpoint($run);
            }

            $run->update([
                ImportRun::FIELD_STATUS => ImportRunStatus::Running,
                ImportRun::FIELD_OWNER_TOKEN => bin2hex(random_bytes(16)),
                ImportRun::FIELD_FINISHED_AT => null,
                ImportRun::FIELD_ERROR_MESSAGE => null,
            ]);

            return $this->checkpoint($run);
        });
    }


    /**
     * @throws Throwable
     */
    public function commit(
        ImportRunCheckpoint $run,
        ImportBatch         $batch,
        Closure             $write,
        bool                $complete = false,
    ): ImportRunCheckpoint {
        return DB::transaction(function () use ($run, $batch, $write, $complete): ImportRunCheckpoint {
            $model = $this->ownedRun($run);

            if ($model->checkpoint !== $run->checkpoint) {
                throw new RuntimeException('Import batch was already committed or its checkpoint is stale.');
            }

            $this->validateBatch($run, $batch);
            $write();
            $this->writeRejections($run->runId, $batch);
            $this->rememberIds($run->runId, $batch->processedIds);

            $model->update([
                ImportRun::FIELD_CHECKPOINT => $batch->checkpoint,
                ImportRun::FIELD_FETCHED => $batch->counters->fetched,
                ImportRun::FIELD_ACCEPTED => $batch->counters->accepted,
                ImportRun::FIELD_FLAGGED => $batch->counters->flagged,
                ImportRun::FIELD_REJECTED => $batch->counters->rejected,
                ImportRun::FIELD_STATUS => $complete ? ImportRunStatus::Completed : ImportRunStatus::Running,
                ImportRun::FIELD_FINISHED_AT => $complete ? now() : null,
                ImportRun::FIELD_ERROR_MESSAGE => null,
            ]);

            return $this->checkpoint($model);
        });
    }

    public function processedIds(int $runId): array
    {
        return DB::table('import_processed_titles')->where('import_run_id', $runId)->pluck('external_id')->all();
    }

    /**
     * @throws Throwable
     */
    public function bindSnapshot(ImportRunCheckpoint $run, string $snapshotId): void
    {
        DB::transaction(function () use ($run, $snapshotId): void {
            $model = $this->ownedRun($run);
            $stored = $model->getAttribute(ImportRun::FIELD_SNAPSHOT_ID);

            if ($model->checkpoint > 0 && $stored === null) {
                throw new RuntimeException('Legacy import checkpoint has no dump snapshot. Start without --resume.');
            }

            if ($stored !== null && $stored !== $snapshotId) {
                throw new RuntimeException('The dump snapshot changed. Start a new import without --resume.');
            }

            $model->update([ImportRun::FIELD_SNAPSHOT_ID => $snapshotId]);
        });
    }


    /**
     * @throws Throwable
     */
    public function fail(ImportRunCheckpoint $run, string $message): void
    {
        DB::transaction(function () use ($run, $message): void {
            $this->ownedRun($run)->update([
                ImportRun::FIELD_STATUS => ImportRunStatus::Failed,
                ImportRun::FIELD_FINISHED_AT => now(),
                ImportRun::FIELD_ERROR_MESSAGE => $message,
            ]);
        });
    }


    private function ownedRun(ImportRunCheckpoint $run): ImportRun
    {
        $model = ImportRun::query()->whereKey($run->runId)->lockForUpdate()->first();

        if ($model === null || $model->getAttribute(ImportRun::FIELD_OWNER_TOKEN) !== $run->ownerToken
            || $model->status !== ImportRunStatus::Running) {
            throw new RuntimeException('Import run is no longer owned by this worker.');
        }

        return $model;
    }

    private function validateBatch(ImportRunCheckpoint $run, ImportBatch $batch): void
    {
        $ids = array_unique($batch->processedIds);

        if (count($ids) !== count($batch->processedIds)
            || $batch->checkpoint - $run->checkpoint !== count($ids)
            || $batch->counters->fetched !== $batch->checkpoint
            || $batch->counters->rejected - $run->counters->rejected !== count($batch->rejections)
            || $batch->counters->accepted < $run->counters->accepted
            || $batch->counters->flagged < $run->counters->flagged) {
            throw new InvalidArgumentException('Import batch counters and identities are inconsistent.');
        }

        $rejected = [];
        foreach ($batch->rejections as $rejection) {
            if (!in_array($rejection->externalId, $ids, true) || isset($rejected[$rejection->externalId])) {
                throw new InvalidArgumentException('Import rejection does not match one batch identity.');
            }
            $rejected[$rejection->externalId] = true;
        }

        if (DB::table('import_processed_titles')->where('import_run_id', $run->runId)->whereIn('external_id', $ids)->exists()) {
            throw new RuntimeException('Import identity was already committed.');
        }
    }

    private function writeRejections(int $runId, ImportBatch $batch): void
    {
        if ($batch->processedIds === []) {
            return;
        }

        ImportRejection::query()->where(ImportRejection::FIELD_IMPORT_RUN_ID, $runId)
            ->whereIn(ImportRejection::FIELD_EXTERNAL_ID, $batch->processedIds)->delete();

        foreach ($batch->rejections as $rejection) {
            ImportRejection::query()->create([
                ImportRejection::FIELD_IMPORT_RUN_ID => $runId,
                ImportRejection::FIELD_EXTERNAL_ID => $rejection->externalId,
                ImportRejection::FIELD_REASON => $rejection->reason,
                ImportRejection::FIELD_CONTEXT => $rejection->context === [] ? null : $rejection->context,
            ]);
        }
    }

    private function rememberIds(int $runId, array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('import_processed_titles')->insert(array_map(
                static fn (string $id): array => ['import_run_id' => $runId, 'external_id' => $id],
                $chunk,
            ));
        }
    }

    private function checkpoint(ImportRun $run): ImportRunCheckpoint
    {
        return new ImportRunCheckpoint(
            runId: $run->getKey(),
            checkpoint: $run->checkpoint,
            counters: new ImportCounters($run->fetched, $run->accepted, $run->flagged, $run->rejected),
            limit: $run->limit,
            ownerToken: (string) $run->getAttribute(ImportRun::FIELD_OWNER_TOKEN),
            completed: $run->status === ImportRunStatus::Completed,
        );
    }
}
