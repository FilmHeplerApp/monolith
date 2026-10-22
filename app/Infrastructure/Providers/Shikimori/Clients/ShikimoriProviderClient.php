<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Clients;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use App\Infrastructure\Providers\Shikimori\ShikimoriConfig;
use Generator;
use InvalidArgumentException;
use RuntimeException;

final readonly class ShikimoriProviderClient implements ProviderClientContract
{
    public function __construct(
        private ShikimoriGraphQLClient $client,
        private ShikimoriTitleMapper   $mapper,
    ) {}

    public function source(): ProviderSource
    {
        return ProviderSource::Shikimori;
    }

    public function fetchTitles(?int $limit = 50, int $offset = 0): Generator
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Fetch limit must be positive or null.');
        }

        if ($offset < 0) {
            throw new InvalidArgumentException('Fetch offset must be zero or greater.');
        }

        $pageSize = ShikimoriConfig::pageSize();
        if ($pageSize < 1) {
            throw new InvalidArgumentException('Shikimori page size must be positive.');
        }

        return $this->iterateTitles($limit, $offset, $pageSize);
    }

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitle
    {
        $rows = $this->rows($this->client->query($this->animeByIdQuery(), ['ids' => $externalId]));

        return isset($rows[0]) ? $this->mapper->map($rows[0]) : null;
    }


    /** @return Generator<int, ProviderTitle> */
    private function iterateTitles(?int $limit, int $offset, int $pageSize): Generator
    {

        $page = intdiv($offset, $pageSize) + 1;
        $skip = $offset % $pageSize;
        $yielded = 0;
        $seenPages = [];

        while ($limit === null || $yielded < $limit) {
            $rows = $this->fetchPage($page, $pageSize);

            if ($rows === []) {
                $rows = $this->fetchPage($page, $pageSize);
                if ($rows === []) {
                    return;
                }
            }

            $fingerprint = hash('sha256', json_encode(array_column($rows, 'id'), JSON_THROW_ON_ERROR));
            if (isset($seenPages[$fingerprint])) {
                throw new RuntimeException("Shikimori repeated anime page {$seenPages[$fingerprint]} at page $page.");
            }
            $seenPages[$fingerprint] = $page;

            foreach (array_slice($rows, $skip) as $row) {
                if ($limit !== null && $yielded >= $limit) {
                    return;
                }

                yield $this->mapper->map($row);
                $yielded++;
            }

            $skip = 0;
            $page++;

            if (count($rows) < $pageSize) {
                break;
            }
        }
    }

    private function fetchPage(int $page, int $pageSize): array
    {
        return $this->rows($this->client->query($this->animeQuery(), ['page' => $page, 'limit' => $pageSize]));
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $data): array
    {
        $rows = $data['animes'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Invalid Shikimori response: animes must be a list.');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id']) || (!is_int($row['id']) && !is_string($row['id'])) || trim((string) $row['id']) === '') {
                throw new RuntimeException('Invalid Shikimori response: anime row must have an id.');
            }
        }

        return $rows;
    }

    private function animeByIdQuery(): string
    {
        $fields = $this->animeFields();

        return <<<GRAPHQL
        query (\$ids: String!) {
          animes(ids: \$ids, limit: 1) {
            {$fields}
          }
        }
        GRAPHQL;
    }

    private function animeQuery(): string
    {
        $fields = $this->animeFields();

        return <<<GRAPHQL
        query (\$page: PositiveInt!, \$limit: PositiveInt!) {
          animes(page: \$page, limit: \$limit, order: id) {
            {$fields}
          }
        }
        GRAPHQL;
    }

    private function animeFields(): string
    {
        return <<<'GRAPHQL'
        id malId name russian english japanese synonyms
        kind status score duration episodes rating franchise
        airedOn { year }
        releasedOn { year }
        description
        poster { originalUrl }
        genres { id name russian kind }
        studios { id name }
        updatedAt
        scoresStats { score count }
        GRAPHQL;
    }
}
