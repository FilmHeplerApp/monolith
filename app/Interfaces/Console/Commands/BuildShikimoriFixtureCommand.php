<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use Generator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(
    name: 'shikimori:build-fixture',
    description: 'Assemble a curated test fixture from the dump Shikimori (edge cases р.11)',
)]
final class BuildShikimoriFixtureCommand extends Command
{
    private const string DUMP_DISK = 'local';

    private const string DUMP_ANIME_PATH = 'import/shikimori_anime.ndjson';

    private const string DUMP_GENRES_PATH = 'import/shikimori_genres.ndjson';

    private const string CHECKPOINT_PATH = 'import/shikimori_anime.checkpoint';

    private const string LOCK_PATH = 'import/shikimori_dump.lock';

    private const int MIN_TITLES = 250;

    private const string FIXTURE_DIR = 'static/Fixtures/Shikimori';


    public function handle(): int
    {
        $lock = null;

        try {
            $disk = Storage::disk(self::DUMP_DISK);

            foreach ([self::DUMP_ANIME_PATH, self::DUMP_GENRES_PATH, self::CHECKPOINT_PATH] as $file) {
                if (!$disk->exists($file)) {
                    $this->error("Dump not found: $file. At first: php artisan shikimori:dump");

                    return self::FAILURE;
                }
            }

            $lock = @fopen($disk->path(self::LOCK_PATH), 'c+b');

            if ($lock === false) {
                throw new RuntimeException('Cannot open the dump lock.');
            }

            if (!flock($lock, LOCK_SH | LOCK_NB)) {
                throw new RuntimeException('Shikimori dump is running. Try building the fixture after it finishes.');
            }

            $checkpoint = $this->checkpoint($disk);
            $selected = $this->select($disk->path(self::DUMP_ANIME_PATH), $checkpoint['bytes'], $checkpoint['titles']);
            $genres = iterator_to_array($this->streamNdjson($disk->path(self::DUMP_GENRES_PATH)));

            if ($genres === []) {
                throw new RuntimeException('Insufficient dump data: genres are empty. Run shikimori:dump again.');
            }

            $dir = base_path(self::FIXTURE_DIR);
            File::ensureDirectoryExists($dir);

            $this->writeNdjson($dir.'/animes.ndjson', $selected);
            $this->writeNdjson($dir.'/genres.ndjson', $genres);

            $message = sprintf('Fixture assembled: %d titles, %d genres.', count($selected), count($genres));
            $this->info($message);
            $this->line('-> '.self::FIXTURE_DIR);
            Log::info($message, [
                'titles' => count($selected),
                'genres' => count($genres),
                'path' => self::FIXTURE_DIR,
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Failed to build Shikimori fixture.', ['exception' => $exception]);
            $this->error('Failed to build Shikimori fixture: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
    }


    /**
     * @throws JsonException
     */
    private function checkpoint(FilesystemAdapter $disk): array
    {
        $checkpoint = json_decode($disk->get(self::CHECKPOINT_PATH) ?? '', true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($checkpoint) || ($checkpoint['version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported dump checkpoint. Run shikimori:dump again.');
        }

        foreach (['page', 'bytes', 'titles'] as $field) {
            if (!isset($checkpoint[$field]) || !is_int($checkpoint[$field]) || $checkpoint[$field] < 0) {
                throw new RuntimeException('Invalid dump checkpoint. Run shikimori:dump again.');
            }
        }

        return $checkpoint;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function select(string $path, int $bytes, int $expectedTitles): array
    {
        $buckets = $this->getBuckets();
        $counts = array_fill_keys(array_keys($buckets), 0);
        $picked = [];
        $titles = 0;

        foreach ($this->streamNdjson($path, $bytes) as $row) {
            $titles++;
            foreach ($buckets as $i => [$predicate, $cap]) {
                if ($counts[$i] < $cap && $predicate($row)) {
                    $picked[$row['id']] = $row;
                    $counts[$i]++;
                }
            }
        }

        if ($titles !== $expectedTitles) {
            throw new RuntimeException('Anime dump row count does not match its checkpoint.');
        }

        $selected = array_values($picked);

        $missing = array_keys(array_filter($counts, static fn (int $count): bool => $count === 0));

        if (count($selected) < self::MIN_TITLES || $missing !== []) {
            throw new RuntimeException(sprintf(
                'Insufficient dump data: selected %d/%d titles; missing scenarios: %s. Run shikimori:dump again.',
                count($selected),
                self::MIN_TITLES,
                $missing === [] ? 'none' : implode(', ', $missing),
            ));
        }

        usort($selected, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $selected;
    }

    /**
     * @return Generator<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function streamNdjson(string $path, ?int $bytes = null): Generator
    {
        $fh = @fopen($path, 'rb');

        if ($fh === false) {
            throw new RuntimeException('Cannot open dump: '.$path);
        }

        try {
            $size = fstat($fh);
            $remaining = $bytes ?? ($size['size'] ?? 0);

            if ($size === false || $remaining > $size['size']) {
                throw new RuntimeException('Anime dump is shorter than its checkpoint. Run shikimori:dump again.');
            }

            while ($remaining > 0) {
                $line = fgets($fh, $remaining + 1);

                if ($line === false) {
                    throw new RuntimeException('Cannot read the committed dump boundary.');
                }

                $remaining -= strlen($line);

                if ($bytes !== null && !str_ends_with($line, "\n")) {
                    throw new RuntimeException('Checkpoint is not at an NDJSON record boundary.');
                }

                $line = trim($line);
                if ($line !== '') {
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                    if (!is_array($row) || !isset($row['id']) || (!is_string($row['id']) && !is_int($row['id']))
                        || (string) $row['id'] === '') {
                        throw new RuntimeException('Invalid dump row: expected an object with an id.');
                    }

                    yield $row;
                }
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * @return array<string, array{0: callable, 1: int}>
     */
    private function getBuckets(): array
    {
        $description = static fn (array $r): string => (string) ($r['description'] ?? '');
        $poster = static fn (array $r): bool => !empty(($r['poster'] ?? [])['originalUrl']);
        $year = static fn (array $r) => ($r['airedOn'] ?? [])['year'] ?? null;
        $studios = static fn (array $r): int => count($r['studios'] ?? []);
        $bbcode = static fn (array $r): bool => (bool) preg_match('/\[[a-zA-Z]+(=[^\]]+)?]/', $description($r));

        return [
            'no_ru_or_en' => [static fn ($r) => empty($r['russian']) && empty($r['english']), 15],
            'only_en' => [static fn ($r) => empty($r['russian']) && !empty($r['english']), 15],
            'only_ru' => [static fn ($r) => !empty($r['russian']) && empty($r['english']), 15],
            'no_description' => [static fn ($r) => trim($description($r)) === '', 20],
            'no_poster' => [static fn ($r) => !$poster($r), 12],
            'no_score' => [static fn ($r) => empty($r['score']), 20],
            'no_studios' => [static fn ($r) => $studios($r) === 0, 15],
            'multiple_studios' => [static fn ($r) => $studios($r) >= 2 && $studios($r) <= 3, 15],
            'no_year' => [static fn ($r) => $year($r) === null, 15],
            'bbcode' => [$bbcode, 25],
            'long_description' => [static fn ($r) => mb_strlen($description($r)) > 1000, 20],
            'pv' => [static fn ($r) => ($r['kind'] ?? '') === 'pv', 10],
            'cm' => [static fn ($r) => ($r['kind'] ?? '') === 'cm', 10],
            'ova' => [static fn ($r) => ($r['kind'] ?? '') === 'ova', 8],
            'ona' => [static fn ($r) => ($r['kind'] ?? '') === 'ona', 8],
            'special' => [static fn ($r) => ($r['kind'] ?? '') === 'special', 8],
            'tv_special' => [static fn ($r) => ($r['kind'] ?? '') === 'tv_special', 8],
            'ongoing' => [static fn ($r) => ($r['status'] ?? '') === 'ongoing', 15],
            'announced' => [static fn ($r) => ($r['status'] ?? '') === 'anons', 15],
            'complete_tv' => [static fn ($r) => ($r['kind'] ?? '') === 'tv' && !empty($r['russian']) && !empty($r['english'])
                && trim($description($r)) !== '' && $poster($r) && !empty($r['score']) && $studios($r) >= 1, 60],
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

        if (File::put($path, implode("\n", $lines)."\n") === false) {
            throw new RuntimeException('Cannot write fixture: '.$path);
        }
    }
}
