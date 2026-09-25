<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Application\Import\DTOs\ImportCounters;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Import\Enums\RejectionReason;

interface ImportRunRecorderContract
{
    public function start(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit,
    ): ImportRunCheckpoint;

    public function resume(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit,
    ): ?ImportRunCheckpoint;

    public function progress(int $runId, ImportCounters $counters, int $checkpoint): void;

    /** @param array<string, mixed> $context */
    public function rejection(
        int             $runId,
        string          $externalId,
        RejectionReason $reason,
        array           $context = [],
    ): void;

    public function complete(int $runId, ImportCounters $counters, int $checkpoint): void;

    public function fail(int $runId, ImportCounters $counters, int $checkpoint, string $message): void;
}
