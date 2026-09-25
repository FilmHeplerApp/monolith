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

    public function fetchTitles(?int $limit = null, int $offset = 0): Generator
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
        $rows = $this->client->query(
            $this->animeByIdQuery(),
            ['ids' => $externalId],
        )['animes'] ?? [];

        return isset($rows[0]) ? $this->mapper->map($rows[0]) : null;
    }


    /** @return Generator<int, ProviderTitle> */
    private function iterateTitles(?int $limit, int $offset, int $pageSize): Generator
    {

        $page = intdiv($offset, $pageSize) + 1;
        $skip = $offset % $pageSize;
        $yielded = 0;

        while ($limit === null || $yielded < $limit) {
            $rows = $this->client->query(
                $this->animeQuery(),
                ['page' => $page, 'limit' => $pageSize],
            )['animes'] ?? [];

            if ($rows === []) {
                break;
            }

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
