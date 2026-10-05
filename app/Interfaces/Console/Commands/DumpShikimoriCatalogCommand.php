<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriGraphQLClient;
use App\Infrastructure\Providers\Shikimori\Dumps\ShikimoriDumpWriter;
use App\Infrastructure\Providers\Shikimori\ShikimoriConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class DumpShikimoriCatalogCommand extends Command
{
    private const string DISK = 'local';


    protected $signature = 'shikimori:dump {--resume : Continue from the last successful page}';

    protected $description = 'Anime catalog dump Shikimori in NDJSON (storage/app/private/import)';


    public function handle(ShikimoriGraphQLClient $client): int
    {
        $writer = null;

        try {
            $limit = ShikimoriConfig::pageSize();

            if ($limit < 1) {
                throw new RuntimeException('Shikimori page size must be positive.');
            }

            $writer = new ShikimoriDumpWriter(Storage::disk(self::DISK));
            $page = $writer->start((bool) $this->option('resume'));
            $this->info("Starting from page {$page}.");

            while (true) {
                $animes = $this->fetchAnimes($client, $page, $limit);

                if ($animes === []) {
                    $animes = $this->fetchAnimes($client, $page, $limit);

                    if ($animes === []) {
                        $this->line("End of catalog confirmed at page {$page}.");
                        break;
                    }
                }

                $writer->appendPage($page, $animes);
                $this->line("page {$page}: +".count($animes)." (total {$writer->titleCount()})");
                $page++;
            }

            $genres = $this->rows($client->query($this->genresQuery()), 'genres');
            $writer->writeGenres($genres);
            $this->info("Done: titles {$writer->titleCount()}, genres ".count($genres).'.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Failed to dump Shikimori catalog.', ['exception' => $exception]);
            $this->error('Failed to dump Shikimori catalog: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $writer?->close();
        }
    }


    /** @return list<array<string, mixed>> */
    private function fetchAnimes(ShikimoriGraphQLClient $client, int $page, int $limit): array
    {
        return $this->rows($client->query($this->animeQuery(), ['page' => $page, 'limit' => $limit]), 'animes');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function rows(array $data, string $field): array
    {
        $rows = $data[$field] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException("Invalid Shikimori response: {$field} must be a list.");
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'])
                || (! is_string($row['id']) && ! is_int($row['id'])) || (string) $row['id'] === '') {
                throw new RuntimeException("Invalid Shikimori response: {$field} contains a row without an id.");
            }
        }

        return $rows;
    }

    private function animeQuery(): string
    {
        return <<<'GRAPHQL'
        query ($page: PositiveInt!, $limit: PositiveInt!) {
          animes(page: $page, limit: $limit, order: id) {
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
          }
        }
        GRAPHQL;
    }

    private function genresQuery(): string
    {
        return <<<'GRAPHQL'
        query {
          genres(entryType: Anime) {
            id name russian kind
          }
        }
        GRAPHQL;
    }
}
