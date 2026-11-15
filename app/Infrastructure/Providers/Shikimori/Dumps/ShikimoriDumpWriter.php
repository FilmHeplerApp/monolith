<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Dumps;

use App\Infrastructure\Import\Dumps\DumpLocation;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

final class ShikimoriDumpWriter
{
    private $lock = null;


    private $stream = null;


    private array $checkpoint = ['version' => 1, 'page' => 0, 'bytes' => 0, 'titles' => 0];


    public function __construct(
        private readonly Filesystem   $disk,
        private readonly DumpLocation $location,
    ) {}


    public function start(bool $resume): int
    {
        foreach ([$this->location->data, $this->location->checkpoint, $this->location->lock, $this->location->taxonomy] as $path) {
            if ($path === null) {
                continue;
            }
            $directory = dirname($path);
            if (!is_dir($this->disk->path($directory)) && !$this->disk->makeDirectory($directory)) {
                throw new RuntimeException('Cannot create the dump directory.');
            }
        }

        $this->lock = @fopen($this->disk->path($this->location->lock), 'c+b');

        if ($this->lock === false) {
            $this->lock = null;
            throw new RuntimeException('Cannot open the dump lock.');
        }

        if (!flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another Shikimori dump is already running.');
        }

        $this->checkpoint = ['version' => 1, 'page' => 0, 'bytes' => 0, 'titles' => 0];

        if ($resume) {
            $this->restoreCheckpoint();
        }

        $this->stream = @fopen($this->disk->path($this->location->data), 'c+b');

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

        if (!ftruncate($this->stream, $this->checkpoint['bytes'])
            || fseek($this->stream, $this->checkpoint['bytes']) !== 0) {
            throw new RuntimeException('Cannot restore the committed anime dump boundary.');
        }

        $this->flush();

        if (!$resume) {
            $this->atomicPut($this->location->checkpoint, json_encode($this->checkpoint, JSON_THROW_ON_ERROR));
        }

        return $this->checkpoint['page'] + 1;
    }


    public function seenPages(): array
    {
        return $this->checkpoint['seen_pages'] ?? [];
    }


    public function appendPage(int $page, array $rows, array $seenPages): void
    {
        if ($page !== $this->checkpoint['page'] + 1 || $rows === []) {
            throw new RuntimeException('Only the next non-empty anime page can be committed.');
        }

        $stream = $this->stream;

        if (!is_resource($stream)) {
            throw new RuntimeException('Dump stream is not open.');
        }

        $data = $this->toNdjson($rows);
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $bytes = fwrite($stream, substr($data, $written));

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

        if ($seenPages !== []) {
            $checkpoint['seen_pages'] = $seenPages;
        }

        $this->atomicPut($this->location->checkpoint, json_encode($checkpoint, JSON_THROW_ON_ERROR));
        $this->checkpoint = $checkpoint;
    }


    public function writeGenres(array $rows): void
    {
        if ($rows === [] || $this->location->taxonomy === null) {
            return;
        }

        $this->atomicPut($this->location->taxonomy, $this->toNdjson($rows));
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


    private function restoreCheckpoint(): void
    {
        if (!$this->disk->exists($this->location->checkpoint)) {
            if ($this->disk->exists($this->location->data) && $this->disk->size($this->location->data) > 0) {
                throw new RuntimeException('Non-empty anime dump has no checkpoint. Restart without --resume.');
            }

            $this->atomicPut($this->location->checkpoint, json_encode($this->checkpoint, JSON_THROW_ON_ERROR));

            return;
        }

        $checkpoint = json_decode($this->disk->get($this->location->checkpoint) ?? '', true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($checkpoint) || ($checkpoint['version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported dump checkpoint. Restart without --resume.');
        }

        foreach (['page', 'bytes', 'titles'] as $field) {
            if (!isset($checkpoint[$field]) || !is_int($checkpoint[$field]) || $checkpoint[$field] < 0) {
                throw new RuntimeException('Invalid dump checkpoint. Restart without --resume.');
            }
        }

        if (array_key_exists('seen_pages', $checkpoint) && !is_array($checkpoint['seen_pages'])) {
            throw new RuntimeException('Invalid dump checkpoint. Restart without --resume.');
        }

        $this->checkpoint = $checkpoint;
    }

    private function atomicPut(string $path, string $contents): void
    {
        $temporary = $path.'.tmp';

        if (!$this->disk->put($temporary, $contents)) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        if (!$this->disk->move($temporary, $path)) {
            throw new RuntimeException("Cannot commit {$path}.");
        }
    }

    private function flush(): void
    {
        $stream = $this->stream;

        if (!is_resource($stream)) {
            throw new RuntimeException('Dump stream is not open.');
        }

        if (!fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('Cannot flush the anime dump.');
        }
    }


    private function toNdjson(array $rows): string
    {
        return $rows === [] ? '' : implode("\n", array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $rows,
        ))."\n";
    }
}
