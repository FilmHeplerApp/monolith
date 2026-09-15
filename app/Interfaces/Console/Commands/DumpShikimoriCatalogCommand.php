<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriGraphQLClient;
use App\Infrastructure\Providers\Shikimori\ShikimoriConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class DumpShikimoriCatalogCommand extends Command
{
    protected $signature = 'shikimori:dump {--resume : Continue from the last successful page}';

    protected $description = 'Anime catalog dump Shikimori in NDJSON (storage/app/private/import)';

    private const string DISK = 'local';

    private const string ANIME_FILE_PATH = 'import/shikimori_anime.ndjson';

    private const string GENRES_FILE_PATH = 'import/shikimori_genres.ndjson';

    private const string CHECKPOINT_PATH = 'import/shikimori_anime.checkpoint';

    /**
     * @throws JsonException
     */
    public function handle(ShikimoriGraphQLClient $client): int
    {
        $disk = Storage::disk(self::DISK);
        $limit = ShikimoriConfig::pageSize();

        $startPage = $this->resolveStartPage($disk);

        $titleCount = $this->dumpAnimes($client, $disk, $startPage, $limit);
        $genreCount = $this->dumpGenres($client, $disk);

        $this->info("Done: titles {$titleCount}, genres {$genreCount}.");

        return self::SUCCESS;
    }

    private function resolveStartPage(Filesystem $disk): int
    {
        if ($this->option('resume')) {
            $page = ($disk->exists(self::CHECKPOINT_PATH) ? (int) $disk->get(self::CHECKPOINT_PATH) : 0) + 1;
            $this->info("Continuing from page {$page}.");

            return $page;
        }

        $disk->delete([self::ANIME_FILE_PATH, self::CHECKPOINT_PATH]);
        $this->info('Dumps deleted.');

        return 1;
    }

    /**
     * @throws JsonException
     */
    private function dumpAnimes(ShikimoriGraphQLClient $client, Filesystem $disk, int $startPage, int $limit): int
    {
        $page = $startPage;
        $total = 0;

        while (true) {
            $animes = $client->query($this->animeQuery(), ['page' => $page, 'limit' => $limit])['animes'] ?? [];

            if ($animes === []) {
                break;
            }

            $disk->append(self::ANIME_FILE_PATH, $this->toNdjson($animes));
            $disk->put(self::CHECKPOINT_PATH, (string) $page);
            $total += count($animes);
            $this->line("page {$page}: +".count($animes)." (total {$total})");
            $page++;
        }

        return $total;
    }

    /**
     * @throws JsonException
     */
    private function dumpGenres(ShikimoriGraphQLClient $client, Filesystem $disk): int
    {
        $genres = $client->query($this->genresQuery())['genres'] ?? [];
        $disk->put(self::GENRES_FILE_PATH, $this->toNdjson($genres));

        return count($genres);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws JsonException
     */
    private function toNdjson(array $rows): string
    {
        return implode("\n", array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $rows,
        ));
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
