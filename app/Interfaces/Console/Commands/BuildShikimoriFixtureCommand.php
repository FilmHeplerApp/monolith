<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class BuildShikimoriFixtureCommand extends Command
{
    protected $signature = 'shikimori:build-fixture';

    protected $description = 'Assemble a curated test fixture from the dump Shikimori (edge cases р.11)';

    private const string DUMP_DISK = 'local';
    private const string DUMP_ANIME = 'import/shikimori_anime.ndjson';
    private const string DUMP_GENRES = 'import/shikimori_genres.ndjson';
    private const string FIXTURE_DIR = 'tests/Fixtures/Shikimori';

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $disk = Storage::disk(self::DUMP_DISK);

        foreach ([self::DUMP_ANIME, self::DUMP_GENRES] as $file) {
            if (!$disk->exists($file)) {
                $this->error("Dump not found: {$file}. At first: php artisan shikimori:dump");

                return self::FAILURE;
            }
        }

        $selected = $this->select($disk->path(self::DUMP_ANIME));

        $dir = base_path(self::FIXTURE_DIR);
        File::ensureDirectoryExists($dir);
        $this->writeNdjson($dir . '/animes.ndjson', $selected);
        File::copy($disk->path(self::DUMP_GENRES), $dir . '/genres.ndjson');

        $this->info(sprintf('The fixture is assembled: %d records.', count($selected)));
        $this->line('-> ' . self::FIXTURE_DIR . '/animes.ndjson (+ genres.ndjson)');

        return self::SUCCESS;
    }

    /**
     *
     * @return list<array<string, mixed>>
     * @throws JsonException
     */
    private function select(string $path): array
    {
        $buckets = $this->buckets();
        $counts = array_fill(0, count($buckets), 0);
        $picked = [];

        $fh = fopen($path, 'rb');
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            foreach ($buckets as $i => [$predicate, $cap]) {
                if ($counts[$i] < $cap && $predicate($row)) {
                    $picked[$row['id']] = $row;
                    $counts[$i]++;
                }
            }
        }
        fclose($fh);

        $selected = array_values($picked);
        usort($selected, static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

        return $selected;
    }

    /**
     * @return list<array{0: callable, 1: int}>
     */
    private function buckets(): array
    {
        $desc = static fn(array $r): string => (string)($r['description'] ?? '');
        $poster = static fn(array $r): bool => !empty(($r['poster'] ?? [])['originalUrl']);
        $year = static fn(array $r) => ($r['airedOn'] ?? [])['year'] ?? null;
        $studios = static fn(array $r): int => count($r['studios'] ?? []);
        $bbcode = static fn(array $r): bool => (bool)preg_match('/\[[a-zA-Z]+(=[^\]]+)?]/', $desc($r));

        return [
            [static fn($r) => empty($r['russian']) && empty($r['english']), 15],
            [static fn($r) => empty($r['russian']) && !empty($r['english']), 15],
            [static fn($r) => !empty($r['russian']) && empty($r['english']), 15],
            [static fn($r) => trim($desc($r)) === '', 20],
            [static fn($r) => !$poster($r), 12],
            [static fn($r) => empty($r['score']), 20],
            [static fn($r) => $studios($r) === 0, 15],
            [static fn($r) => $studios($r) >= 2 && $studios($r) <= 3, 15],
            [static fn($r) => $year($r) === null, 15],
            [$bbcode, 25],
            [static fn($r) => mb_strlen($desc($r)) > 1000, 20],
            [static fn($r) => ($r['kind'] ?? '') === 'pv', 10],
            [static fn($r) => ($r['kind'] ?? '') === 'cm', 10],
            [static fn($r) => ($r['kind'] ?? '') === 'ova', 8],
            [static fn($r) => ($r['kind'] ?? '') === 'ona', 8],
            [static fn($r) => ($r['kind'] ?? '') === 'special', 8],
            [static fn($r) => ($r['kind'] ?? '') === 'tv_special', 8],
            [static fn($r) => ($r['status'] ?? '') === 'ongoing', 15],
            [static fn($r) => ($r['status'] ?? '') === 'anons', 15],
            [static fn($r) => ($r['kind'] ?? '') === 'tv' && !empty($r['russian']) && !empty($r['english'])
                && trim($desc($r)) !== '' && $poster($r) && !empty($r['score']) && $studios($r) >= 1, 60],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @throws JsonException
     */
    private function writeNdjson(string $path, array $rows): void
    {
        $lines = array_map(
            static fn(array $r): string => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $rows,
        );

        File::put($path, implode("\n", $lines) . "\n");
    }
}
