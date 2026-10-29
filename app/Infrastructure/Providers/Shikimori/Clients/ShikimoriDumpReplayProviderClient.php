<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Clients;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderSnapshotContract;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Shikimori\Dumps\ShikimoriDumpReader;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use Generator;
use InvalidArgumentException;
use Throwable;

final readonly class ShikimoriDumpReplayProviderClient implements ProviderClientContract, ProviderSnapshotContract
{
    public function __construct(
        private ShikimoriTitleMapper $mapper,
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

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitle
    {
        foreach ($this->fetchTitles(null) as $title) {
            if ($title->externalId === $externalId) {
                return $title;
            }
        }

        return null;
    }


    /** @return Generator<int, ProviderTitle>
     * @throws Throwable
     */
    private function iterateTitles(?int $limit, int $offset): Generator
    {
        try {
            $yielded = 0;

            foreach ($this->reader->rows($offset) as $row) {
                yield $this->mapper->map($row);
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
