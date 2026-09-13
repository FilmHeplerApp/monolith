<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriGraphQLClient;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class DumpShikimoriCatalogCommand extends Command
{
    protected $signature = 'shikimori:dump {--resume : Continue from the last successful page}';

    protected $description = 'Anime catalog dump Shikimori in NDJSON (storage/app/private/import)';

    private const string DISK = 'local';
    private const string ANIME_FILE = 'import/shikimori_anime.ndjson';
    private const string GENRES_FILE = 'import/shikimori_genres.ndjson';
    private const string CHECKPOINT = 'import/shikimori_anime.checkpoint';

    /**
     * @throws JsonException
     */
    public function handle(ShikimoriGraphQLClient $client): int
    {
        $limit = (int)config('shikimori.page_size');
        $disk = Storage::disk(self::DISK);

        if ($this->option('resume')) {
            $page = ($disk->exists(self::CHECKPOINT) ? (int)$disk->get(self::CHECKPOINT) : 0) + 1;
            $this->info("Continuing from the page {$page}.");
        } else {
            $page = 1;
            $disk->delete([self::ANIME_FILE, self::CHECKPOINT]);
            $this->info('Fresh dump.');
        }

        $total = 0;

        while (true) {
            $animes = $client->query($this->animeQuery(), ['page' => $page, 'limit' => $limit])['animes'] ?? [];

            if ($animes === []) {
                break;
            }

            $this->appendNdjson($disk, self::ANIME_FILE, $animes);
            $disk->put(self::CHECKPOINT, (string)$page);
            $total += count($animes);
            $this->line("page {$page}: +" . count($animes) . " (in total {$total})");
            $page++;
        }

        $genres = $client->query($this->genresQuery())['genres'] ?? [];
        $disk->delete(self::GENRES_FILE);
        $this->appendNdjson($disk, self::GENRES_FILE, $genres);

        $this->info("Done: titles {$total}, genres " . count($genres) . '.');

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @throws JsonException
     */
    private function appendNdjson(Filesystem $disk, string $file, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $block = implode("\n", array_map(
            static fn(array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $rows,
        ));

        $disk->append($file, $block);
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
