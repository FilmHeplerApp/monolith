<?php

declare(strict_types=1);

namespace App\Application\Import\Streams;

use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ImportTitleStreamContract;
use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderSnapshotContract;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use Generator;
use RuntimeException;

final readonly class SnapshotProviderTitleStream implements ImportTitleStreamContract
{
    public function __construct(
        private ProviderClientContract    $client,
        private ProviderSnapshotContract  $snapshot,
        private ImportRunRecorderContract $recorder,
        private ImportRunCheckpoint       $run,
    ) {}

    public function titles(): Generator
    {
        try {
            $this->recorder->bindSnapshot($this->run, $this->snapshot->snapshotId());
            $remaining = $this->run->limit === null ? null : max(0, $this->run->limit - $this->run->checkpoint);

            if ($remaining === 0) {
                return;
            }

            $seen = array_fill_keys($this->recorder->processedIds($this->run->runId), true);

            foreach ($this->client->fetchTitles($remaining, $this->run->checkpoint) as $title) {
                if (isset($seen[$title->externalId])) {
                    throw new RuntimeException('Dump contains a repeated provider identity: '.$title->externalId);
                }
                $seen[$title->externalId] = true;
                yield $title;
            }
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        $this->snapshot->close();
    }
}
