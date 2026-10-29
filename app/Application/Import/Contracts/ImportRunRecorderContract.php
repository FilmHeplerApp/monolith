<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Application\Import\DTOs\ImportBatch;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use Closure;

interface ImportRunRecorderContract
{
    public function start(ProviderSource $provider, ProviderDataSource $dataSource, ?int $limit): ImportRunCheckpoint;

    public function resume(ProviderSource $provider, ProviderDataSource $dataSource, ?int $limit): ?ImportRunCheckpoint;

    public function commit(
        ImportRunCheckpoint $run,
        ImportBatch         $batch,
        Closure             $write,
        bool                $complete = false,
    ): ImportRunCheckpoint;

    public function processedIds(int $runId): array;

    public function bindSnapshot(ImportRunCheckpoint $run, string $snapshotId): void;

    public function fail(ImportRunCheckpoint $run, string $message): void;
}
