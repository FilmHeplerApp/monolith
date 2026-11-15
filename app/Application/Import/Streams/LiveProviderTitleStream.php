<?php

declare(strict_types=1);

namespace App\Application\Import\Streams;

use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ImportTitleStreamContract;
use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use Generator;

final readonly class LiveProviderTitleStream implements ImportTitleStreamContract
{
    public function __construct(
        private ProviderClientContract    $client,
        private ImportRunRecorderContract $recorder,
        private ImportRunCheckpoint       $run,
    ) {}

    public function titles(): Generator
    {
        $remaining = $this->run->limit === null ? null : max(0, $this->run->limit - $this->run->checkpoint);

        if ($remaining === 0) {
            return;
        }

        $seen = array_fill_keys($this->recorder->processedIds($this->run->runId), true);
        $yielded = 0;

        foreach ($this->client->fetchTitles(null, 0) as $title) {
            if (isset($seen[$title->externalId])) {
                continue;
            }

            $seen[$title->externalId] = true;
            yield $title;
            $yielded++;

            if ($remaining !== null && $yielded >= $remaining) {
                return;
            }
        }
    }

    public function close(): void {}
}
