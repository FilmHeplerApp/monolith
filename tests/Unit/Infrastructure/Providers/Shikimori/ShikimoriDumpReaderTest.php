<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers\Shikimori;

use App\Infrastructure\Providers\Shikimori\Clients\DumpReplayProviderClient;
use App\Infrastructure\Providers\Shikimori\Dumps\ShikimoriDumpReader;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CommittedShikimoriDump;

final class ShikimoriDumpReaderTest extends TestCase
{
    private string $directory;
    private string $path;


    #[Test]
    public function it_ignores_whole_and_partial_rows_after_the_committed_boundary(): void
    {
        file_put_contents($this->path, "{\"id\":\"2\"}\n{\"id\":", FILE_APPEND);
        $client = new DumpReplayProviderClient(new ShikimoriTitleMapper, $this->path);

        self::assertSame(['1'], array_map(fn ($title) => $title->externalId, iterator_to_array($client->fetchTitles(null))));
    }

    #[Test]
    public function it_fails_immediately_while_a_writer_holds_the_lock(): void
    {
        $lock = fopen($this->directory.'/shikimori_dump.lock', 'c+b');
        flock($lock, LOCK_EX | LOCK_NB);
        $reader = new ShikimoriDumpReader($this->path);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dump is running');

        try {
            $reader->snapshotId();
        } finally {
            fclose($lock);
        }
    }

    #[Test]
    public function it_holds_the_snapshot_lock_and_releases_it_when_the_limit_is_reached(): void
    {
        $client = new DumpReplayProviderClient(new ShikimoriTitleMapper, $this->path);
        $client->snapshotId();
        $writer = fopen($this->directory.'/shikimori_dump.lock', 'c+b');

        try {
            self::assertFalse(flock($writer, LOCK_EX | LOCK_NB));
            self::assertCount(1, iterator_to_array($client->fetchTitles(1)));
            self::assertTrue(flock($writer, LOCK_EX | LOCK_NB));
        } finally {
            $client->close();
            fclose($writer);
        }
    }

    #[Test]
    public function it_fingerprints_committed_content_and_ignores_uncommitted_appends(): void
    {
        $reader = new ShikimoriDumpReader($this->path);
        $first = $reader->snapshotId();
        $reader->close();
        file_put_contents($this->path, '{"id":"2"}', FILE_APPEND);
        self::assertSame($first, $reader->snapshotId());
        $reader->close();
        CommittedShikimoriDump::create($this->directory, "{\"id\":\"2\"}\n");
        self::assertNotSame($first, $reader->snapshotId());
        $reader->close();
    }

    #[Test]
    #[DataProvider('invalidSnapshots')]
    public function it_rejects_invalid_metadata_and_releases_the_lock(string $change): void
    {
        $checkpoint = ['version' => 1, 'page' => 1, 'bytes' => filesize($this->path), 'titles' => 1];
        match ($change) {
            'missing' => unlink($this->directory.'/shikimori_anime.checkpoint'),
            'version' => $checkpoint['version'] = 2,
            'negative' => $checkpoint['bytes'] = -1,
            'short_file' => $checkpoint['bytes'] += 20,
            'boundary' => $checkpoint['bytes']--,
            'wrong_count' => $checkpoint['titles'] = 2,
        };
        if ($change !== 'missing') {
            file_put_contents($this->directory.'/shikimori_anime.checkpoint', json_encode($checkpoint));
        }
        $reader = new ShikimoriDumpReader($this->path);
        try {
            iterator_to_array($reader->rows());
            self::fail('Invalid snapshot must fail.');
        } catch (RuntimeException) {
            $reader->close();
            $lock = fopen($this->directory.'/shikimori_dump.lock', 'c+b');
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            fclose($lock);
        }
    }

    public static function invalidSnapshots(): array
    {
        return array_map(static fn ($value) => [$value], ['missing', 'version', 'negative', 'short_file', 'boundary', 'wrong_count']);
    }


    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/dump-reader-'.bin2hex(random_bytes(8));
        $this->path = CommittedShikimoriDump::create($this->directory, "{\"id\":\"1\"}\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
}
