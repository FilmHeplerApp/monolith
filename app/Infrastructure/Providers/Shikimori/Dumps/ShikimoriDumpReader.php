<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Dumps;

use Generator;
use RuntimeException;

final class ShikimoriDumpReader
{
    /** @var resource|null */
    private $lock = null;
    /** @var resource|null */
    private $stream = null;
    private int $bytes = 0;
    private int $titles = 0;
    private ?string $fingerprint = null;


    public function __construct(private readonly string $path) {}

    public function snapshotId(): string
    {
        if ($this->fingerprint !== null) {
            return $this->fingerprint;
        }

        try {
            $this->lock = @fopen(dirname($this->path).'/shikimori_dump.lock', 'c+b');

            if ($this->lock === false || !flock($this->lock, LOCK_SH | LOCK_NB)) {
                throw new RuntimeException('Shikimori dump is running or its lock is not readable.');
            }

            $metadata = @file_get_contents(dirname($this->path).'/shikimori_anime.checkpoint');
            if ($metadata === false) {
                throw new RuntimeException('Dump checkpoint is not readable. Run shikimori:dump first.');
            }
            $checkpoint = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($checkpoint) || ($checkpoint['version'] ?? null) !== 1) {
                throw new RuntimeException('Unsupported dump checkpoint. Run shikimori:dump first.');
            }

            foreach (['page', 'bytes', 'titles'] as $field) {
                if (!isset($checkpoint[$field]) || !is_int($checkpoint[$field]) || $checkpoint[$field] < 0) {
                    throw new RuntimeException('Invalid dump checkpoint.');
                }
            }

            $this->bytes = $checkpoint['bytes'];
            $this->titles = $checkpoint['titles'];
            $this->stream = @fopen($this->path, 'rb');

            if ($this->stream === false) {
                throw new RuntimeException('Shikimori dump is not readable: '.$this->path);
            }

            $stat = fstat($this->stream);

            if ($stat === false || $stat['size'] < $this->bytes) {
                throw new RuntimeException('Anime dump is shorter than its checkpoint.');
            }

            if ($this->bytes > 0 && (fseek($this->stream, $this->bytes - 1) !== 0 || fread($this->stream, 1) !== "\n")) {
                throw new RuntimeException('Checkpoint is not at an NDJSON record boundary.');
            }

            if (!rewind($this->stream)) {
                throw new RuntimeException('Cannot rewind the dump.');
            }

            $hash = hash_init('sha256');
            hash_update($hash, json_encode([$this->bytes, $this->titles], JSON_THROW_ON_ERROR));
            $remaining = $this->bytes;
            $lineCount = 0;

            while ($remaining > 0) {
                $chunk = fread($this->stream, min(8192, $remaining));

                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Cannot read the committed dump snapshot.');
                }

                hash_update($hash, $chunk);
                $lineCount += substr_count($chunk, "\n");
                $remaining -= strlen($chunk);
            }

            if ($lineCount !== $this->titles) {
                throw new RuntimeException('Anime dump row count does not match its checkpoint.');
            }

            if (!rewind($this->stream)) {
                throw new RuntimeException('Cannot rewind the dump.');
            }

            return $this->fingerprint = hash_final($hash);
        } catch (\Throwable $exception) {
            $this->close();

            throw $exception;
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    public function rows(int $offset = 0): Generator
    {
        $this->snapshotId();

        if ($offset > $this->titles) {
            throw new RuntimeException('Import offset exceeds the committed dump title count.');
        }

        $remaining = $this->bytes;
        $count = 0;

        while ($remaining > 0) {
            $line = fgets($this->stream, $remaining + 1);

            if ($line === false || $line === '' || !str_ends_with($line, "\n")) {
                throw new RuntimeException('Cannot read a complete committed NDJSON record.');
            }

            $remaining -= strlen($line);
            $count++;
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($row) || array_is_list($row)) {
                throw new RuntimeException("Expected a JSON object at dump row $count.");
            }

            if ($count > $offset) {
                yield $row;
            }
        }

        if ($count !== $this->titles) {
            throw new RuntimeException('Anime dump row count does not match its checkpoint.');
        }
    }

    public function close(): void
    {
        foreach ([$this->stream, $this->lock] as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->stream = $this->lock = null;
        $this->fingerprint = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
