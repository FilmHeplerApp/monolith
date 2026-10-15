<?php

declare(strict_types=1);

namespace Tests\Feature\Interfaces\Console\Commands;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use JsonException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DumpShikimoriCatalogCommandTest extends TestCase
{
    private const string ANIME_PATH = 'import/shikimori_anime.ndjson';

    private const string CHECKPOINT_PATH = 'import/shikimori_anime.checkpoint';


    private FilesystemAdapter $disk;


    #[Test]
    public function it_stops_before_committing_a_repeated_nonempty_page(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1', 'name' => 'First']]]])
                ->push(['data' => ['animes' => [['id' => '2']]]])
                ->push(['data' => ['animes' => [['id' => '1', 'name' => 'Changed metadata']]]]),
        ]);

        $this->artisan('shikimori:dump')
            ->expectsOutput('Failed to dump Shikimori catalog: Shikimori repeated anime page 1 at page 3.')
            ->assertFailed();

        self::assertSame(['1', '2'], $this->ids($this->disk));
        self::assertSame(2, $this->checkpoint($this->disk)['page']);
        self::assertSame(strlen($this->disk->get(self::ANIME_PATH)), $this->checkpoint($this->disk)['bytes']);
        self::assertSame($this->seenPages([1 => ['1'], 2 => ['2']]), $this->checkpoint($this->disk)['seen_pages']);
        Http::assertSentCount(3);
        Log::shouldHaveReceived('error')->once();
    }

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
            'version' => 1,
            'page' => 2,
            'bytes' => strlen($disk->get(self::ANIME_PATH)),
            'titles' => 4,
            'seen_pages' => $this->seenPages([1 => ['1', '2'], 2 => ['3', '4']]),
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
        Log::shouldHaveReceived('info')->once()->with('Done: titles 4, genres 1.', [
            'titles' => 4,
            'genres' => 1,
        ]);
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
            'version' => 1,
            'page' => 1,
            'bytes' => strlen($new),
            'titles' => 1,
            'seen_pages' => $this->seenPages([1 => ['1']]),
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
                ->push(['data' => ['genres' => [['id' => '10', 'name' => 'Action']]]]),
        ]);

        $this->artisan('shikimori:dump')
            ->expectsOutput('Done: titles 5, genres 1.')
            ->assertSuccessful();

        $disk = $this->disk;
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids($disk));
        self::assertSame([
            'version' => 1,
            'page' => 2,
            'bytes' => strlen($disk->get(self::ANIME_PATH)),
            'titles' => 5,
            'seen_pages' => $this->seenPages([1 => ['1', '2', '3'], 2 => ['4', '5']]),
        ], $this->checkpoint($disk));
        self::assertSame("{\"id\":\"10\",\"name\":\"Action\"}\n", $disk->get('import/shikimori_genres.ndjson'));
        Http::assertSentCount(5);
    }

    #[Test]
    public function it_keeps_the_previous_genres_dump_when_the_response_is_empty(): void
    {
        $previousGenres = "{\"id\":\"10\",\"name\":\"Action\",\"russian\":\"Экшен\"}\n";
        $this->disk->put('import/shikimori_genres.ndjson', $previousGenres);
        $disk = $this->partialDisk($this->disk);
        $disk->shouldNotReceive('put')->with('import/shikimori_genres.ndjson.tmp', Mockery::any());
        $disk->shouldNotReceive('move')->with('import/shikimori_genres.ndjson.tmp', 'import/shikimori_genres.ndjson');
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1']]]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['genres' => []]]),
        ]);

        $this->artisan('shikimori:dump')
            ->expectsOutput('Shikimori returned no genres; the existing genres dump was left unchanged.')
            ->assertSuccessful();

        self::assertSame($previousGenres, $this->disk->get('import/shikimori_genres.ndjson'));
        self::assertSame(['1'], $this->ids($this->disk));
        self::assertSame(1, $this->checkpoint($this->disk)['page']);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Shikimori returned no genres; the existing genres dump was left unchanged.');
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function it_does_not_create_an_empty_genres_dump_when_no_previous_dump_exists(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['genres' => []]]),
        ]);

        $this->artisan('shikimori:dump')->assertSuccessful();

        $this->disk->assertMissing('import/shikimori_genres.ndjson');
        $this->disk->assertMissing('import/shikimori_genres.ndjson.tmp');
        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('error');
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function it_does_not_append_a_resumed_page_already_recorded_in_the_checkpoint(): void
    {
        $disk = $this->disk;
        $committed = "{\"id\":\"1\"}\n";
        $fingerprint = hash('sha256', implode("\0", ['1']));
        $disk->put(self::ANIME_PATH, $committed);
        $disk->put(self::CHECKPOINT_PATH, json_encode([
            'version' => 1,
            'page' => 1,
            'bytes' => strlen($committed),
            'titles' => 1,
            'seen_pages' => [$fingerprint => 1],
        ], JSON_THROW_ON_ERROR));
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1', 'name' => 'Again']]]]),
        ]);

        $this->artisan('shikimori:dump --resume')
            ->expectsOutput('Failed to dump Shikimori catalog: Shikimori repeated anime page 1 at page 2.')
            ->assertFailed();

        self::assertSame(['1'], $this->ids($disk));
        self::assertSame(1, $this->checkpoint($disk)['page']);
        self::assertSame([$fingerprint => 1], $this->checkpoint($disk)['seen_pages']);
        self::assertSame($committed, $disk->get(self::ANIME_PATH));
        Http::assertSentCount(1);
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

    /**
     * @param  array<int, list<string>>  $pages
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
