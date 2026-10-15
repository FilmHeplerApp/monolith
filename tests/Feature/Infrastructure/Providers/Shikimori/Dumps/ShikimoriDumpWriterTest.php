<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Providers\Shikimori\Dumps;

use App\Infrastructure\Providers\Shikimori\Dumps\ShikimoriDumpWriter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ShikimoriDumpWriterTest extends TestCase
{
    private const string ANIME_PATH = 'import/shikimori_anime.ndjson';

    private const string GENRES_PATH = 'import/shikimori_genres.ndjson';

    private const string CHECKPOINT_PATH = 'import/shikimori_anime.checkpoint';


    private FilesystemAdapter $disk;


    #[Test]
    public function it_stores_seen_pages_with_the_committed_page(): void
    {
        $writer = new ShikimoriDumpWriter($this->disk);
        $seen = $this->seenPages([1 => ['1']]);

        try {
            $writer->start(false);
            $writer->appendPage(1, [['id' => '1']], $seen);
        } finally {
            $writer->close();
        }

        self::assertSame(['1'], $this->ids());
        self::assertSame([
            'version' => 1,
            'page' => 1,
            'bytes' => strlen("{\"id\":\"1\"}\n"),
            'titles' => 1,
            'seen_pages' => $seen,
        ], $this->checkpoint());
        self::assertSame(1, $writer->titleCount());
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function it_restores_seen_pages_when_resuming(): void
    {
        $committed = "{\"id\":\"1\"}\n";
        $seen = $this->seenPages([1 => ['1']]);
        $this->disk->put(self::ANIME_PATH, $committed);
        $this->saveCheckpoint(1, strlen($committed), 1, $seen);
        $writer = new ShikimoriDumpWriter($this->disk);

        try {
            self::assertSame(2, $writer->start(true));
            self::assertSame($seen, $writer->seenPages());
        } finally {
            $writer->close();
        }
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function it_truncates_an_uncommitted_tail_when_resuming(): void
    {
        $committed = "{\"id\":\"1\"}\n";
        $this->disk->put(self::ANIME_PATH, $committed . "{\"id\":\"2\"}\n{\"id\":");
        $this->saveCheckpoint(1, strlen($committed), 1);
        $writer = new ShikimoriDumpWriter($this->disk);

        try {
            self::assertSame(2, $writer->start(true));
        } finally {
            $writer->close();
        }

        self::assertSame($committed, $this->disk->get(self::ANIME_PATH));
    }

    #[Test]
    public function it_rejects_an_empty_page_and_a_page_that_is_not_next(): void
    {
        $writer = new ShikimoriDumpWriter($this->disk);

        try {
            $writer->start(false);

            try {
                $writer->appendPage(1, [], []);
                self::fail('Empty pages must not be committed.');
            } catch (RuntimeException $exception) {
                self::assertSame('Only the next non-empty anime page can be committed.', $exception->getMessage());
            }

            try {
                $writer->appendPage(2, [['id' => '1']], $this->seenPages([2 => ['1']]));
                self::fail('Pages must be committed in order.');
            } catch (RuntimeException $exception) {
                self::assertSame('Only the next non-empty anime page can be committed.', $exception->getMessage());
            }
        } finally {
            $writer->close();
        }
    }

    #[Test]
    public function it_leaves_genres_unchanged_when_the_list_is_empty(): void
    {
        $previous = "{\"id\":\"10\"}\n";
        $this->disk->put(self::GENRES_PATH, $previous);
        $writer = new ShikimoriDumpWriter($this->disk);

        try {
            $writer->start(false);
            $writer->writeGenres([]);
        } finally {
            $writer->close();
        }

        self::assertSame($previous, $this->disk->get(self::GENRES_PATH));
        $this->disk->assertMissing(self::GENRES_PATH . '.tmp');
    }

    #[Test]
    #[DataProvider('writeOperations')]
    public function it_fails_if_genres_cannot_be_written_or_committed(string $operation): void
    {
        $this->disk->put(self::GENRES_PATH, 'previous genres');
        $faulty = $this->partialDisk();

        if ($operation === 'put') {
            $faulty->shouldReceive('put')->with(self::GENRES_PATH . '.tmp', Mockery::any())->andReturn(false);
        } else {
            $faulty->shouldReceive('move')->with(self::GENRES_PATH . '.tmp', self::GENRES_PATH)->andReturn(false);
        }

        $writer = new ShikimoriDumpWriter($faulty);

        try {
            $writer->start(false);
            $writer->writeGenres([['id' => '10']]);
            self::fail('Genre write must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Cannot ' . ($operation === 'put' ? 'write ' : 'commit ') . self::GENRES_PATH . '.',
                $exception->getMessage(),
            );
        } finally {
            $writer->close();
        }

        self::assertSame('previous genres', $this->disk->get(self::GENRES_PATH));
    }

    /**
     * @throws JsonException
     */
    #[Test]
    #[DataProvider('writeOperations')]
    public function a_failed_checkpoint_commit_keeps_the_previous_checkpoint(string $operation): void
    {
        $faulty = $this->partialDisk();
        $failed = false;

        if ($operation === 'put') {
            $faulty->shouldReceive('put')->andReturnUsing(function ($path, $contents) use (&$failed): bool {
                if (!$failed && $path === self::CHECKPOINT_PATH . '.tmp'
                    && json_decode($contents, true)['page'] === 2) {
                    $failed = true;

                    return false;
                }

                return $this->disk->put($path, $contents);
            });
        } else {
            $faulty->shouldReceive('move')->andReturnUsing(function ($from, $to) use (&$failed): bool {
                if (!$failed && $to === self::CHECKPOINT_PATH
                    && json_decode($this->disk->get($from), true)['page'] === 2) {
                    $failed = true;

                    return false;
                }

                return $this->disk->move($from, $to);
            });
        }

        $writer = new ShikimoriDumpWriter($faulty);

        try {
            $writer->start(false);
            $writer->appendPage(1, [['id' => '1']], $this->seenPages([1 => ['1']]));

            try {
                $writer->appendPage(2, [['id' => '2']], $this->seenPages([1 => ['1'], 2 => ['2']]));
                self::fail('The second checkpoint must fail.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Cannot ' . ($operation === 'put' ? 'write ' : 'commit ') . self::CHECKPOINT_PATH . '.',
                    $exception->getMessage(),
                );
            }
        } finally {
            $writer->close();
        }

        self::assertTrue($failed);
        self::assertSame(['1', '2'], $this->ids());
        self::assertSame([
            'version' => 1,
            'page' => 1,
            'bytes' => strlen("{\"id\":\"1\"}\n"),
            'titles' => 1,
            'seen_pages' => $this->seenPages([1 => ['1']]),
        ], $this->checkpoint());

        $resume = new ShikimoriDumpWriter($this->disk);

        try {
            self::assertSame(2, $resume->start(true));
        } finally {
            $resume->close();
        }

        self::assertSame(['1'], $this->ids());
    }

    /**
     * @throws JsonException
     */
    #[Test]
    #[DataProvider('writeOperations')]
    public function it_truncates_the_dump_before_committing_the_initial_checkpoint(string $operation): void
    {
        $dump = "{\"id\":\"1\"}\n";
        $this->disk->put(self::ANIME_PATH, $dump);
        $this->saveCheckpoint(1, strlen($dump), 1);
        $faulty = $this->partialDisk();
        $assertReset = function (string $contents): void {
            self::assertSame('', $this->disk->get(self::ANIME_PATH));
            self::assertSame([
                'version' => 1, 'page' => 0, 'bytes' => 0, 'titles' => 0,
            ], json_decode($contents, true, 512, JSON_THROW_ON_ERROR));
        };

        if ($operation === 'put') {
            $faulty->shouldReceive('put')
                ->once()
                ->with(self::CHECKPOINT_PATH . '.tmp', Mockery::any())
                ->andReturnUsing(function (string $path, string $contents) use ($assertReset): bool {
                    $assertReset($contents);

                    return false;
                });
        } else {
            $faulty->shouldReceive('move')
                ->once()
                ->with(self::CHECKPOINT_PATH . '.tmp', self::CHECKPOINT_PATH)
                ->andReturnUsing(function (string $from, string $to) use ($assertReset): bool {
                    $assertReset($this->disk->get($from));

                    return false;
                });
        }

        $writer = new ShikimoriDumpWriter($faulty);

        try {
            $writer->start(false);
            self::fail('The initial checkpoint must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Cannot ' . ($operation === 'put' ? 'write ' : 'commit ') . self::CHECKPOINT_PATH . '.',
                $exception->getMessage(),
            );
        } finally {
            $writer->close();
        }

        self::assertSame('', $this->disk->get(self::ANIME_PATH));
        self::assertSame([
            'version' => 1, 'page' => 1, 'bytes' => strlen($dump), 'titles' => 1,
        ], $this->checkpoint());

        $resume = new ShikimoriDumpWriter($this->disk);

        try {
            $resume->start(true);
            self::fail('Resume must refuse a dump shorter than its checkpoint.');
        } catch (RuntimeException $exception) {
            self::assertSame('Anime dump is shorter than its checkpoint. Restart without --resume.', $exception->getMessage());
        } finally {
            $resume->close();
        }

        self::assertSame('', $this->disk->get(self::ANIME_PATH));
        self::assertSame(1, $this->checkpoint()['page']);
    }

    #[Test]
    public function it_refuses_to_resume_when_the_checkpoint_is_not_an_object(): void
    {
        $this->disk->put(self::ANIME_PATH, "{\"id\":\"1\"}\n");
        $this->disk->put(self::CHECKPOINT_PATH, '1');

        $this->assertStartFails(true, 'Unsupported dump checkpoint. Restart without --resume.');
        self::assertSame(['1'], $this->ids());
    }

    /**
     * @throws JsonException
     */
    #[Test]
    #[DataProvider('nonArraySeenPages')]
    public function it_refuses_to_resume_when_seen_pages_is_not_a_map(mixed $seenPages): void
    {
        $dump = "{\"id\":\"1\"}\n";
        $this->disk->put(self::ANIME_PATH, $dump);
        $this->disk->put(self::CHECKPOINT_PATH, json_encode([
            'version' => 1, 'page' => 1, 'bytes' => strlen($dump), 'titles' => 1,
            'seen_pages' => $seenPages,
        ], JSON_THROW_ON_ERROR));

        $this->assertStartFails(true, 'Invalid dump checkpoint. Restart without --resume.');
        self::assertSame($dump, $this->disk->get(self::ANIME_PATH));
    }

    #[Test]
    public function it_refuses_to_resume_a_dump_shorter_than_the_checkpoint(): void
    {
        $this->disk->put(self::ANIME_PATH, '');
        $this->saveCheckpoint(1, 100, 1);

        $this->assertStartFails(true, 'Anime dump is shorter than its checkpoint. Restart without --resume.');
        self::assertSame('', $this->disk->get(self::ANIME_PATH));
        self::assertSame(100, $this->checkpoint()['bytes']);
    }

    #[Test]
    public function it_refuses_to_resume_without_a_checkpoint_for_an_existing_dump(): void
    {
        $dump = "{\"id\":\"1\"}\n";
        $this->disk->put(self::ANIME_PATH, $dump);

        $this->assertStartFails(true, 'Non-empty anime dump has no checkpoint. Restart without --resume.');
        self::assertSame($dump, $this->disk->get(self::ANIME_PATH));
    }

    #[Test]
    public function it_refuses_a_checkpoint_in_the_middle_of_a_record(): void
    {
        $dump = "{\"id\":\"1\"}\n";
        $this->disk->put(self::ANIME_PATH, $dump);
        $this->saveCheckpoint(1, strlen($dump) - 1, 1);

        $this->assertStartFails(true, 'Checkpoint is not at an NDJSON record boundary. Restart without --resume.');
        self::assertSame($dump, $this->disk->get(self::ANIME_PATH));
    }

    #[Test]
    public function it_does_not_write_a_checkpoint_when_the_anime_file_cannot_be_opened(): void
    {
        $this->disk->makeDirectory(self::ANIME_PATH);

        $this->assertStartFails(false, 'Cannot open the anime dump.');
        $this->disk->assertMissing(self::CHECKPOINT_PATH);
    }

    #[Test]
    public function it_rejects_a_second_exclusive_lock(): void
    {
        $first = new ShikimoriDumpWriter($this->disk);
        $second = new ShikimoriDumpWriter($this->disk);

        try {
            $first->start(false);

            try {
                $second->start(false);
                self::fail('The second dump must not acquire the lock.');
            } catch (RuntimeException $exception) {
                self::assertSame('Another Shikimori dump is already running.', $exception->getMessage());
            }
        } finally {
            $second->close();
            $first->close();
        }
    }

    public static function writeOperations(): array
    {
        return [['put'], ['move']];
    }

    public static function nonArraySeenPages(): array
    {
        return [
            'string' => ['not-a-map'],
            'integer' => [1],
            'null' => [null],
            'boolean' => [true],
        ];
    }


    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = Storage::fake('local');
    }


    private function assertStartFails(bool $resume, string $message): void
    {
        $writer = new ShikimoriDumpWriter($this->disk);

        try {
            $writer->start($resume);
            self::fail($message);
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        } finally {
            $writer->close();
        }
    }

    private function partialDisk(): FilesystemAdapter&MockInterface
    {
        $mock = Mockery::mock(FilesystemAdapter::class, [$this->disk->getDriver(), $this->disk->getAdapter(), $this->disk->getConfig()])->makePartial();
        $mock->shouldNotReceive('append');
        $mock->shouldReceive('get')->passthru()->byDefault();
        $mock->shouldNotReceive('get')->with(self::ANIME_PATH);

        return $mock;
    }

    /**
     * @throws JsonException
     */
    private function saveCheckpoint(
        int   $page,
        int   $bytes,
        int   $titles,
        array $seenPages = []
    ): void {
        $checkpoint = ['version' => 1, 'page' => $page, 'bytes' => $bytes, 'titles' => $titles];

        if ($seenPages !== []) {
            $checkpoint['seen_pages'] = $seenPages;
        }

        $this->disk->put(self::CHECKPOINT_PATH, json_encode($checkpoint, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws JsonException
     */
    private function checkpoint(): array
    {
        return json_decode($this->disk->get(self::CHECKPOINT_PATH), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, list<string>> $pages
     * @return array<string, int>
     */
    private function seenPages(array $pages): array
    {
        $seen = [];

        foreach ($pages as $page => $ids) {
            $seen[hash('sha256', implode("\0", $ids))] = $page;
        }

        return $seen;
    }

    private function ids(): array
    {
        return array_map(
        /**
         * @throws JsonException
         */ static fn(string $line): string => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['id'],
            explode("\n", trim($this->disk->get(self::ANIME_PATH))),
        );
    }
}
