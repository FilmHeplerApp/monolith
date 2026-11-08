<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Clients;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderSnapshotContract;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Shikimori\Dumps\ShikimoriDumpReader;
use Generator;
use InvalidArgumentException;
use RuntimeException;

final readonly class ShikimoriDumpProviderClient implements ProviderClientContract, ProviderSnapshotContract
{
    public function __construct(
        private ShikimoriDumpReader  $reader,
    ) {}

    public function source(): ProviderSource
    {
        return ProviderSource::Shikimori;
    }

    public function snapshotId(): string
    {
        return $this->reader->snapshotId();
    }

    public function close(): void
    {
        $this->reader->close();
    }

    public function fetchTitles(?int $limit = 50, int $offset = 0): Generator
    {
        if (($limit !== null && $limit < 1) || $offset < 0) {
            throw new InvalidArgumentException('Fetch limit must be positive and offset must not be negative.');
        }

        return $this->iterateTitles($limit, $offset);
    }

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitleRecord
    {
        foreach ($this->fetchTitles(null) as $title) {
            if ($title->externalId === $externalId) {
                return $title;
            }
        }

        return null;
    }


    private function iterateTitles(?int $limit, int $offset): Generator
    {
        try {
            $yielded = 0;

            foreach ($this->reader->rows($offset) as $row) {
                $id = $row['id'] ?? null;
                if ((!is_int($id) && !is_string($id)) || trim((string) $id) === '') {
                    throw new RuntimeException('Invalid Shikimori dump: title must have an id.');
                }
                yield new ProviderTitleRecord($this->source(), (string) $id, $row);
                $yielded++;

                if ($limit !== null && $yielded >= $limit) {
                    return;
                }
            }
        } finally {
            $this->close();
        }
    }
}
