<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'shikimori:build-fixture',
    description: 'Assemble a curated test fixture from the dump Shikimori (edge cases р.11)',
)]
final class BuildShikimoriFixtureCommand extends Command
{
    private const string DUMP_DISK = 'local';

    private const string DUMP_ANIME_PATH = 'import/shikimori_anime.ndjson';

    private const string DUMP_GENRES_PATH = 'import/shikimori_genres.ndjson';

    private const string FIXTURE_DIR = 'tests/Fixtures/Shikimori';

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $disk = Storage::disk(self::DUMP_DISK);

        foreach ([self::DUMP_ANIME_PATH, self::DUMP_GENRES_PATH] as $file) {
            if (! $disk->exists($file)) {
                $this->error("Dump not found: {$file}. At first: php artisan shikimori:dump");

                return self::FAILURE;
            }
        }

        $dir = base_path(self::FIXTURE_DIR);
        File::ensureDirectoryExists($dir);

        $animeCount = $this->buildAnimeFixture($disk->path(self::DUMP_ANIME_PATH), $dir.'/animes.ndjson');
        $genreCount = $this->buildGenreFixture($disk->path(self::DUMP_GENRES_PATH), $dir.'/genres.ndjson');

        $this->info(sprintf('Fixture assembled: %d titles, %d genres.', $animeCount, $genreCount));
        $this->line('-> '.self::FIXTURE_DIR);

        return self::SUCCESS;
    }

    /**
     * @throws JsonException
     */
    private function buildAnimeFixture(string $dumpPath, string $outPath): int
    {
        $selected = $this->select($dumpPath);
        $this->writeNdjson($outPath, $selected);

        return count($selected);
    }

    /**
     * @throws JsonException
     */
    private function buildGenreFixture(string $dumpPath, string $outPath): int
    {
        $genres = iterator_to_array($this->streamNdjson($dumpPath));
        $this->writeNdjson($outPath, $genres);

        return count($genres);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function select(string $path): array
    {
        $buckets = $this->getBuckets();
        $counts = array_fill(0, count($buckets), 0);
        $picked = [];

        foreach ($this->streamNdjson($path) as $row) {
            foreach ($buckets as $i => [$predicate, $cap]) {
                if ($counts[$i] < $cap && $predicate($row)) {
                    $picked[$row['id']] = $row;
                    $counts[$i]++;
                }
            }
        }

        $selected = array_values($picked);
        usort($selected, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $selected;
    }

    /**
     * @return Generator<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function streamNdjson(string $path): Generator
    {
        $fh = fopen($path, 'rb');

        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                }
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * @return list<array{0: callable, 1: int}>
     */
    private function getBuckets(): array
    {
        $description = static fn (array $r): string => (string) ($r['description'] ?? '');
        $poster = static fn (array $r): bool => ! empty(($r['poster'] ?? [])['originalUrl']);
        $year = static fn (array $r) => ($r['airedOn'] ?? [])['year'] ?? null;
        $studios = static fn (array $r): int => count($r['studios'] ?? []);
        $bbcode = static fn (array $r): bool => (bool) preg_match('/\[[a-zA-Z]+(=[^\]]+)?]/', $description($r));

        return [
            [static fn ($r) => empty($r['russian']) && empty($r['english']), 15],
            [static fn ($r) => empty($r['russian']) && ! empty($r['english']), 15],
            [static fn ($r) => ! empty($r['russian']) && empty($r['english']), 15],
            [static fn ($r) => trim($description($r)) === '', 20],
            [static fn ($r) => ! $poster($r), 12],
            [static fn ($r) => empty($r['score']), 20],
            [static fn ($r) => $studios($r) === 0, 15],
            [static fn ($r) => $studios($r) >= 2 && $studios($r) <= 3, 15],
            [static fn ($r) => $year($r) === null, 15],
            [$bbcode, 25],
            [static fn ($r) => mb_strlen($description($r)) > 1000, 20],
            [static fn ($r) => ($r['kind'] ?? '') === 'pv', 10],
            [static fn ($r) => ($r['kind'] ?? '') === 'cm', 10],
            [static fn ($r) => ($r['kind'] ?? '') === 'ova', 8],
            [static fn ($r) => ($r['kind'] ?? '') === 'ona', 8],
            [static fn ($r) => ($r['kind'] ?? '') === 'special', 8],
            [static fn ($r) => ($r['kind'] ?? '') === 'tv_special', 8],
            [static fn ($r) => ($r['status'] ?? '') === 'ongoing', 15],
            [static fn ($r) => ($r['status'] ?? '') === 'anons', 15],
            [static fn ($r) => ($r['kind'] ?? '') === 'tv' && ! empty($r['russian']) && ! empty($r['english'])
                && trim($description($r)) !== '' && $poster($r) && ! empty($r['score']) && $studios($r) >= 1, 60],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws JsonException
     */
    private function writeNdjson(string $path, array $rows): void
    {
        $lines = array_map(
            static fn (array $r): string => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $rows,
        );

        File::put($path, implode("\n", $lines)."\n");
    }
}
