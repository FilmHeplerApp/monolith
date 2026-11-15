<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Persistence\Eloquent\Import\EloquentImportRunRecorder;

trait StartsImportRuns
{
    private function startRun(ProviderSource $provider, ProviderDataSource $source, ?int $limit): ImportRunCheckpoint
    {
        $lock = app(ImportRunLockContract::class);
        $recorder = app(EloquentImportRunRecorder::class);

        return $lock->synchronized($provider, $source, static fn () => $recorder->start($provider, $source, $limit, $lock->assertOwned(...)));
    }

    private function resumeRun(ProviderSource $provider, ProviderDataSource $source, ?int $limit): ?ImportRunCheckpoint
    {
        $lock = app(ImportRunLockContract::class);
        $recorder = app(EloquentImportRunRecorder::class);

        return $lock->synchronized($provider, $source, static fn () => $recorder->resume($provider, $source, $limit, $lock->assertOwned(...)));
    }
}
