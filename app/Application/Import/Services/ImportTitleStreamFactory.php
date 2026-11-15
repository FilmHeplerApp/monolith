<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ImportTitleStreamContract;
use App\Application\Import\Contracts\ProviderSnapshotContract;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Streams\LiveProviderTitleStream;
use App\Application\Import\Streams\SnapshotProviderTitleStream;

final readonly class ImportTitleStreamFactory
{
    public function __construct(private ImportRunRecorderContract $recorder) {}

    public function create(
        ProviderImportContext $context,
        ImportRunCheckpoint   $run,
    ): ImportTitleStreamContract {
        $client = $context->client;

        return $client instanceof ProviderSnapshotContract
            ? new SnapshotProviderTitleStream($client, $client, $this->recorder, $run)
            : new LiveProviderTitleStream($client, $this->recorder, $run);
    }
}
