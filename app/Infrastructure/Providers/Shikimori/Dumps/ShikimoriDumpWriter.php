<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Dumps;

use Illuminate\Filesystem\FilesystemAdapter;
use JsonException;
use RuntimeException;

final class ShikimoriDumpWriter
{
    private const string ANIME_PATH = 'import/shikimori_anime.ndjson';

    private const string GENRES_PATH = 'import/shikimori_genres.ndjson';

    private const string CHECKPOINT_PATH = 'import/shikimori_anime.checkpoint';


    /** @var resource|null */
    private $lock = null;

    /** @var resource|null */
    private $stream = null;

    /** @var array{version: int, page: int, bytes: int, titles: int} */
    private array $checkpoint = ['version' => 1, 'page' => 0, 'bytes' => 0, 'titles' => 0];


    public function __construct(private readonly FilesystemAdapter $disk) {}

    /**
     * @throws JsonException
     */
    public function start(bool $resume): int
    {
        if (! is_dir($this->disk->path('import')) && ! $this->disk->makeDirectory('import')) {
            throw new RuntimeException('Cannot create the dump directory.');
        }

        $this->lock = @fopen($this->disk->path('import/shikimori_dump.lock'), 'c+b');

        if ($this->lock === false) {
            $this->lock = null;
            throw new RuntimeException('Cannot open the dump lock.');
        }

        if (! flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another Shikimori dump is already running.');
        }

        $this->checkpoint = ['version' => 1, 'page' => 0, 'bytes' => 0, 'titles' => 0];

        if ($resume) {
            $this->restoreCheckpoint();
        } else {
            $this->atomicPut(self::CHECKPOINT_PATH, json_encode($this->checkpoint, JSON_THROW_ON_ERROR));
        }

        $this->stream = @fopen($this->disk->path(self::ANIME_PATH), 'c+b');

        if ($this->stream === false) {
            $this->stream = null;
            throw new RuntimeException('Cannot open the anime dump.');
        }

        $stat = fstat($this->stream);

        if ($stat === false || $stat['size'] < $this->checkpoint['bytes']) {
            throw new RuntimeException('Anime dump is shorter than its checkpoint. Restart without --resume.');
        }

        if ($this->checkpoint['bytes'] > 0
            && (fseek($this->stream, $this->checkpoint['bytes'] - 1) !== 0 || fread($this->stream, 1) !== "\n")) {
            throw new RuntimeException('Checkpoint is not at an NDJSON record boundary. Restart without --resume.');
        }

        if (! ftruncate($this->stream, $this->checkpoint['bytes'])
            || fseek($this->stream, $this->checkpoint['bytes']) !== 0) {
            throw new RuntimeException('Cannot restore the committed anime dump boundary.');
        }

        $this->flush();

        return $this->checkpoint['page'] + 1;
    }

    /** @param list<array<string, mixed>> $rows
     * @throws JsonException
     */
    public function appendPage(int $page, array $rows): void
    {
        if ($page !== $this->checkpoint['page'] + 1 || $rows === []) {
            throw new RuntimeException('Only the next non-empty anime page can be committed.');
        }

        $data = $this->toNdjson($rows);
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $bytes = fwrite($this->stream, substr($data, $written));

            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException("Cannot write anime page {$page}.");
            }

            $written += $bytes;
        }

        $this->flush();
        $checkpoint = [
            'version' => 1,
            'page' => $page,
            'bytes' => $this->checkpoint['bytes'] + $written,
            'titles' => $this->checkpoint['titles'] + count($rows),
        ];
        $this->atomicPut(self::CHECKPOINT_PATH, json_encode($checkpoint, JSON_THROW_ON_ERROR));
        $this->checkpoint = $checkpoint;
    }

    /** @param list<array<string, mixed>> $rows
     * @throws JsonException
     */
    public function writeGenres(array $rows): void
    {
        $this->atomicPut(self::GENRES_PATH, $this->toNdjson($rows));
    }

    public function titleCount(): int
    {
        return $this->checkpoint['titles'];
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
            $this->stream = null;
        }

        if (is_resource($this->lock)) {
            fclose($this->lock);
            $this->lock = null;
        }
    }


    /**
     * @throws JsonException
     */
    private function restoreCheckpoint(): void
    {
        if (! $this->disk->exists(self::CHECKPOINT_PATH)) {
            if ($this->disk->exists(self::ANIME_PATH) && $this->disk->size(self::ANIME_PATH) > 0) {
                throw new RuntimeException('Non-empty anime dump has no checkpoint. Restart without --resume.');
            }

            $this->atomicPut(self::CHECKPOINT_PATH, json_encode($this->checkpoint, JSON_THROW_ON_ERROR));

            return;
        }

        $checkpoint = json_decode($this->disk->get(self::CHECKPOINT_PATH) ?? '', true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($checkpoint) || ($checkpoint['version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported dump checkpoint. Restart without --resume.');
        }

        foreach (['page', 'bytes', 'titles'] as $field) {
            if (! isset($checkpoint[$field]) || ! is_int($checkpoint[$field]) || $checkpoint[$field] < 0) {
                throw new RuntimeException('Invalid dump checkpoint. Restart without --resume.');
            }
        }

        $this->checkpoint = $checkpoint;
    }

    private function atomicPut(string $path, string $contents): void
    {
        $temporary = $path.'.tmp';

        if (! $this->disk->put($temporary, $contents)) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        if (! $this->disk->move($temporary, $path)) {
            throw new RuntimeException("Cannot commit {$path}.");
        }
    }

    private function flush(): void
    {
        if (! fflush($this->stream) || ! fsync($this->stream)) {
            throw new RuntimeException('Cannot flush the anime dump.');
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function toNdjson(array $rows): string
    {
        return $rows === [] ? '' : implode("\n", array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $rows,
        ))."\n";
    }
}
