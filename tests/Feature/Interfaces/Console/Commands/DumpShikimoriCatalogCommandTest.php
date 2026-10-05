<?php

declare(strict_types=1);

namespace Tests\Feature\Interfaces\Console\Commands;

use App\Infrastructure\Providers\Shikimori\Dumps\ShikimoriDumpWriter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use JsonException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DumpShikimoriCatalogCommandTest extends TestCase
{
    private const string ANIME_PATH = 'import/shikimori_anime.ndjson';

    private const string CHECKPOINT_PATH = 'import/shikimori_anime.checkpoint';


    private FilesystemAdapter $disk;


    #[Test]
    public function it_dumps_multiple_pages_as_valid_ndjson_and_confirms_the_end(): void
    {
        config(['shikimori.page_size' => 2]);
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1'], ['id' => '2']]]])
                ->push(['data' => ['animes' => [['id' => '3'], ['id' => '4']]]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['genres' => [['id' => '10', 'name' => 'Action']]]]),
        ]);

        $this->artisan('shikimori:dump')->assertSuccessful();

        $disk = $this->disk;
        self::assertSame(['1', '2', '3', '4'], $this->ids($disk));
        self::assertSame([
            'version' => 1, 'page' => 2, 'bytes' => strlen($disk->get(self::ANIME_PATH)), 'titles' => 4,
        ], $this->checkpoint($disk));
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data()['variables'] ?? null)->all();
        self::assertSame([
            ['page' => 1, 'limit' => 2],
            ['page' => 2, 'limit' => 2],
            ['page' => 3, 'limit' => 2],
            ['page' => 3, 'limit' => 2],
            null,
        ], $requests);
        self::assertSame("{\"id\":\"10\",\"name\":\"Action\"}\n", $disk->get('import/shikimori_genres.ndjson'));
        Log::shouldNotHaveReceived('error');
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function it_replaces_the_old_dump_and_checkpoint_without_resume(): void
    {
        $disk = $this->disk;
        $old = "{\"id\":\"99\",\"name\":\"Old title with a long description\"}\n{\"id\":\"100\"}\n";
        $disk->put(self::ANIME_PATH, $old);
        $disk->put('import/shikimori_genres.ndjson', "{\"id\":\"999\"}\n");
        $this->saveCheckpoint($disk, 2, strlen($old), 2);
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1']]]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['genres' => [['id' => '10']]]]),
        ]);

        $this->artisan('shikimori:dump')->assertSuccessful();

        $new = "{\"id\":\"1\"}\n";
        self::assertSame($new, $disk->get(self::ANIME_PATH));
        self::assertSame([
            'version' => 1, 'page' => 1, 'bytes' => strlen($new), 'titles' => 1,
        ], $this->checkpoint($disk));
        self::assertSame("{\"id\":\"10\"}\n", $disk->get('import/shikimori_genres.ndjson'));
        self::assertSame(1, Http::recorded()[0][0]->data()['variables']['page']);
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function it_preserves_a_partial_last_page_and_commits_all_its_rows(): void
    {
        config(['shikimori.page_size' => 3]);
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1'], ['id' => '2'], ['id' => '3']]]])
                ->push(['data' => ['animes' => [['id' => '4'], ['id' => '5']]]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['genres' => []]]),
        ]);

        $this->artisan('shikimori:dump')
            ->expectsOutput('Done: titles 5, genres 0.')
            ->assertSuccessful();

        $disk = $this->disk;
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids($disk));
        self::assertSame([
            'version' => 1, 'page' => 2, 'bytes' => strlen($disk->get(self::ANIME_PATH)), 'titles' => 5,
        ], $this->checkpoint($disk));
        Http::assertSentCount(5);
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function it_resumes_from_the_committed_boundary_and_removes_an_uncommitted_tail(): void
    {
        $disk = $this->disk;
        $committed = "{\"id\":\"1\"}\n";
        $disk->put(self::ANIME_PATH, $committed."{\"id\":\"2\"}\n{\"id\":");
        $this->saveCheckpoint($disk, 1, strlen($committed), 1);
        $this->fakeRemainingPage();

        $this->artisan('shikimori:dump --resume')->assertSuccessful();

        self::assertSame(['1', '2'], $this->ids($disk));
        self::assertSame(2, $this->checkpoint($disk)['page']);
        self::assertSame(strlen($disk->get(self::ANIME_PATH)), $this->checkpoint($disk)['bytes']);
        Http::assertSent(fn ($request) => ($request->data()['variables']['page'] ?? null) === 2);
    }

    /**
     * @throws JsonException
     */
    #[Test]
    #[DataProvider('writeOperations')]
    public function a_failed_checkpoint_commit_does_not_skip_or_duplicate_a_page(string $operation): void
    {
        $disk = $this->disk;
        $faulty = $this->partialDisk($disk);
        $failed = false;

        if ($operation === 'put') {
            $faulty->shouldReceive('put')->andReturnUsing(function ($path, $contents) use ($disk, &$failed): bool {
                if (! $failed && $path === self::CHECKPOINT_PATH.'.tmp'
                    && json_decode($contents, true)['page'] === 2) {
                    $failed = true;

                    return false;
                }

                return $disk->put($path, $contents);
            });
        } else {
            $faulty->shouldReceive('move')->andReturnUsing(function ($from, $to) use ($disk, &$failed): bool {
                if (! $failed && $to === self::CHECKPOINT_PATH
                    && json_decode($disk->get($from), true)['page'] === 2) {
                    $failed = true;

                    return false;
                }

                return $disk->move($from, $to);
            });
        }

        Storage::shouldReceive('disk')->with('local')->andReturn($faulty);
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1']]]])
                ->push(['data' => ['animes' => [['id' => '2']]]]),
        ]);

        $this->artisan('shikimori:dump')
            ->expectsOutput('Failed to dump Shikimori catalog: Cannot '.($operation === 'put' ? 'write ' : 'commit ').self::CHECKPOINT_PATH.'.')
            ->assertFailed();

        self::assertTrue($failed);
        self::assertSame([
            'version' => 1, 'page' => 1, 'bytes' => strlen("{\"id\":\"1\"}\n"), 'titles' => 1,
        ], $this->checkpoint($disk));
        self::assertSame(['1', '2'], $this->ids($disk));
        Log::shouldHaveReceived('error')->once();
        Http::assertSentCount(2);

        $this->fakeRemainingPage();
        $this->artisan('shikimori:dump --resume')->assertSuccessful();

        self::assertSame(['1', '2'], $this->ids($disk));
        self::assertSame(2, $this->checkpoint($disk)['page']);
    }

    #[Test]
    #[DataProvider('writeOperations')]
    public function it_fails_if_genres_cannot_be_written_or_committed(string $operation): void
    {
        $disk = $this->disk;
        $disk->put('import/shikimori_genres.ndjson', 'previous genres');
        $faulty = $this->partialDisk($disk);

        if ($operation === 'put') {
            $faulty->shouldReceive('put')->with('import/shikimori_genres.ndjson.tmp', Mockery::any())->andReturn(false);
        } else {
            $faulty->shouldReceive('move')->with('import/shikimori_genres.ndjson.tmp', 'import/shikimori_genres.ndjson')->andReturn(false);
        }

        Storage::shouldReceive('disk')->with('local')->andReturn($faulty);
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['genres' => [['id' => '10']]]]),
        ]);

        $this->artisan('shikimori:dump')->assertFailed();

        self::assertSame('previous genres', $disk->get('import/shikimori_genres.ndjson'));
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function it_continues_after_a_single_transient_empty_page(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1']]]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['animes' => [['id' => '2']]]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['genres' => []]]),
        ]);

        $this->artisan('shikimori:dump')->assertSuccessful();

        self::assertSame(['1', '2'], $this->ids($this->disk));
        $pages = Http::recorded()->map(fn ($pair) => $pair[0]->data()['variables']['page'] ?? null)->all();
        self::assertSame([1, 2, 2, 3, 3, null], $pages);
    }

    #[Test]
    public function it_does_not_treat_a_missing_animes_field_as_end_of_catalog(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1']]]])
                ->push(['data' => []]),
        ]);

        $this->artisan('shikimori:dump')
            ->expectsOutput('Failed to dump Shikimori catalog: Invalid Shikimori response: animes must be a list.')
            ->assertFailed();

        self::assertSame(1, $this->checkpoint($this->disk)['page']);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function a_transport_failure_keeps_the_last_committed_page(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1']]]])
                ->push([], 503)->push([], 503)->push([], 503),
        ]);

        $this->artisan('shikimori:dump')->assertFailed();

        self::assertSame(1, $this->checkpoint($this->disk)['page']);
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function it_refuses_to_resume_an_old_checkpoint_without_a_byte_offset(): void
    {
        $disk = $this->disk;
        $disk->put(self::ANIME_PATH, "{\"id\":\"1\"}\n");
        $disk->put(self::CHECKPOINT_PATH, '1');
        Http::fake();

        $this->artisan('shikimori:dump --resume')
            ->expectsOutput('Failed to dump Shikimori catalog: Unsupported dump checkpoint. Restart without --resume.')
            ->assertFailed();

        self::assertSame(['1'], $this->ids($disk));
        Http::assertNothingSent();
    }

    #[Test]
    public function it_refuses_to_resume_a_dump_shorter_than_the_checkpoint(): void
    {
        $disk = $this->disk;
        $disk->put(self::ANIME_PATH, '');
        $this->saveCheckpoint($disk, 1, 100, 1);
        Http::fake();

        $this->artisan('shikimori:dump --resume')->assertFailed();

        self::assertSame('', $disk->get(self::ANIME_PATH));
        self::assertSame(100, $this->checkpoint($disk)['bytes']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_refuses_to_resume_without_a_checkpoint_for_an_existing_dump(): void
    {
        $disk = $this->disk;
        $dump = "{\"id\":\"1\"}\n";
        $disk->put(self::ANIME_PATH, $dump);
        Http::fake();

        $this->artisan('shikimori:dump --resume')->assertFailed();

        self::assertSame($dump, $disk->get(self::ANIME_PATH));
        Http::assertNothingSent();
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function it_keeps_the_old_dump_if_the_initial_checkpoint_cannot_be_committed(): void
    {
        $disk = $this->disk;
        $dump = "{\"id\":\"1\"}\n";
        $disk->put(self::ANIME_PATH, $dump);
        $this->saveCheckpoint($disk, 1, strlen($dump), 1);
        $faulty = $this->partialDisk($disk);
        $faulty->shouldReceive('put')->with(self::CHECKPOINT_PATH.'.tmp', Mockery::any())->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($faulty);
        Http::fake();

        $this->artisan('shikimori:dump')->assertFailed();

        self::assertSame($dump, $disk->get(self::ANIME_PATH));
        self::assertSame(1, $this->checkpoint($disk)['page']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_refuses_a_checkpoint_in_the_middle_of_a_record(): void
    {
        $disk = $this->disk;
        $dump = "{\"id\":\"1\"}\n";
        $disk->put(self::ANIME_PATH, $dump);
        $this->saveCheckpoint($disk, 1, strlen($dump) - 1, 1);
        Http::fake();

        $this->artisan('shikimori:dump --resume')->assertFailed();

        self::assertSame($dump, $disk->get(self::ANIME_PATH));
        Http::assertNothingSent();
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function it_returns_failure_when_the_anime_file_cannot_be_opened(): void
    {
        $disk = $this->disk;
        $disk->makeDirectory(self::ANIME_PATH);
        Http::fake();

        $this->artisan('shikimori:dump')
            ->expectsOutput('Failed to dump Shikimori catalog: Cannot open the anime dump.')
            ->assertFailed();

        self::assertSame(0, $this->checkpoint($disk)['page']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_prevents_two_dump_commands_from_writing_at_once(): void
    {
        $writer = new ShikimoriDumpWriter($this->disk);
        $writer->start(false);
        Http::fake();

        try {
            $this->artisan('shikimori:dump')
                ->expectsOutput('Failed to dump Shikimori catalog: Another Shikimori dump is already running.')
                ->assertFailed();
            Http::assertNothingSent();
        } finally {
            $writer->close();
        }
    }

    public static function writeOperations(): array
    {
        return [['put'], ['move']];
    }


    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->disk = Storage::fake('local');
        Log::spy();
    }


    private function partialDisk(FilesystemAdapter $disk): FilesystemAdapter&MockInterface
    {
        $mock = Mockery::mock(FilesystemAdapter::class, [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
        $mock->shouldNotReceive('append');
        $mock->shouldReceive('get')->passthru()->byDefault();
        $mock->shouldNotReceive('get')->with(self::ANIME_PATH);

        return $mock;
    }

    private function fakeRemainingPage(): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '2']]]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['genres' => []]]),
        ]);
    }

    /**
     * @throws JsonException
     */
    private function saveCheckpoint(FilesystemAdapter $disk, int $page, int $bytes, int $titles): void
    {
        $disk->put(self::CHECKPOINT_PATH, json_encode([
            'version' => 1, 'page' => $page, 'bytes' => $bytes, 'titles' => $titles,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @throws JsonException
     */
    private function checkpoint(FilesystemAdapter $disk): array
    {
        return json_decode($disk->get(self::CHECKPOINT_PATH), true, 512, JSON_THROW_ON_ERROR);
    }

    private function ids(FilesystemAdapter $disk): array
    {
        return array_map(
        /**
         * @throws JsonException
         */ static fn (string $line): string => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['id'],
            explode("\n", trim($disk->get(self::ANIME_PATH))),
        );
    }
}
