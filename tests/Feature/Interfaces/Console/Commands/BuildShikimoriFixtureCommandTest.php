<?php

declare(strict_types=1);

namespace Tests\Feature\Interfaces\Console\Commands;

use App\Infrastructure\Import\Dumps\DumpLocation;
use App\Infrastructure\Providers\Shikimori\Dumps\ShikimoriDumpWriter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use TypeError;

final class BuildShikimoriFixtureCommandTest extends TestCase
{
    private const string ANIME_PATH = 'import/shikimori_anime.ndjson';

    private const string GENRES_PATH = 'import/shikimori_genres.ndjson';

    private const string CHECKPOINT_PATH = 'import/shikimori_anime.checkpoint';

    private const string LOCK_PATH = 'import/shikimori_dump.lock';


    private FilesystemAdapter $disk;


    #[Test]
    public function it_reports_invalid_json_and_logs_the_exception(): void
    {
        $this->saveDump("{invalid json}\n", 1);
        File::shouldReceive('put')->never();
        Log::shouldReceive('error')->once()
            ->with('Failed to build Shikimori fixture.', Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof JsonException,
            ));

        $this->artisan('shikimori:build-fixture')
            ->expectsOutput('Failed to build Shikimori fixture: Syntax error')
            ->assertFailed();
    }

    #[Test]
    public function it_catches_errors_during_storage_initialization(): void
    {
        $exception = new TypeError('Invalid storage configuration.');
        Storage::shouldReceive('disk')->once()->with('local')->andThrow($exception);
        Log::shouldReceive('error')->once()
            ->with('Failed to build Shikimori fixture.', ['exception' => $exception]);

        $this->artisan('shikimori:build-fixture')
            ->expectsOutput('Failed to build Shikimori fixture: Invalid storage configuration.')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_immediately_while_the_dump_writer_holds_the_lock(): void
    {
        $this->saveCuratedDump();
        $writer = new ShikimoriDumpWriter($this->disk, DumpLocation::fromConfig('shikimori'));
        $writer->start(false);
        File::shouldReceive('put')->never();

        try {
            $this->artisan('shikimori:build-fixture')
                ->expectsOutput('Failed to build Shikimori fixture: Shikimori dump is running. Try building the fixture after it finishes.')
                ->assertFailed();
        } finally {
            $writer->close();
        }
    }

    #[Test]
    #[DataProvider('uncommittedTails')]
    public function it_reads_only_committed_anime_and_holds_the_lock_through_genres_and_output(string $tail): void
    {
        $committed = $this->saveCuratedDump();
        file_put_contents($this->disk->path(self::ANIME_PATH), $tail, FILE_APPEND);
        $written = [];
        File::shouldReceive('ensureDirectoryExists')->once()->with(base_path('static/Fixtures/Shikimori'));
        File::shouldReceive('put')->twice()->andReturnUsing(function (string $path, string $contents) use (&$written): int {
            $lock = fopen($this->disk->path(self::LOCK_PATH), 'c+b');

            try {
                self::assertFalse(flock($lock, LOCK_EX | LOCK_NB));
            } finally {
                fclose($lock);
            }

            $written[basename($path)] = $contents;

            return strlen($contents);
        });

        $this->artisan('shikimori:build-fixture')->assertSuccessful();

        $ids = static fn (string $ndjson): array => array_column(array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", trim($ndjson)),
        ), 'id');
        self::assertEmpty(array_diff($ids($written['animes.ndjson']), $ids($committed)));
        self::assertGreaterThanOrEqual(250, count($ids($written['animes.ndjson'])));
        self::assertSame($this->disk->get(self::GENRES_PATH), $written['genres.ndjson']);
        self::assertSame($committed.$tail, $this->disk->get(self::ANIME_PATH));
        $titleCount = count($ids($written['animes.ndjson']));
        Log::shouldHaveReceived('info')->once()->with(
            "Fixture assembled: {$titleCount} titles, 1 genres.",
            ['titles' => $titleCount, 'genres' => 1, 'path' => 'static/Fixtures/Shikimori'],
        );
        $this->assertLockReleased();
    }

    public static function uncommittedTails(): array
    {
        return [
            'no tail' => [''],
            'complete row' => ["{\"id\":\"999999999\"}\n"],
            'partial row' => ['{"id":'],
            'complete and partial rows' => ["{\"id\":\"999999999\"}\n{\"id\":"],
        ];
    }

    #[Test]
    #[DataProvider('invalidCheckpoints')]
    public function it_rejects_invalid_checkpoint_data_without_changing_the_fixture(?string $checkpoint): void
    {
        $this->saveDump("{\"id\":\"1\"}\n", 1);

        if ($checkpoint === null) {
            $this->disk->delete(self::CHECKPOINT_PATH);
        } else {
            $this->disk->put(self::CHECKPOINT_PATH, $checkpoint);
        }

        File::shouldReceive('put')->never();
        $this->artisan('shikimori:build-fixture')->assertFailed();
        $this->assertLockReleased();
    }

    public static function invalidCheckpoints(): array
    {
        return [
            'missing' => [null],
            'old version' => ['1'],
            'negative offset' => ['{"version":1,"page":1,"bytes":-1,"titles":1}'],
            'missing offset' => ['{"version":1,"page":1,"titles":1}'],
            'offset past EOF' => ['{"version":1,"page":1,"bytes":1000,"titles":1}'],
            'offset within record' => ['{"version":1,"page":1,"bytes":5,"titles":1}'],
            'wrong title count' => ['{"version":1,"page":1,"bytes":11,"titles":2}'],
        ];
    }

    #[Test]
    public function it_fails_without_writing_when_committed_data_cannot_cover_the_fixture(): void
    {
        $this->saveDump("{\"id\":\"1\"}\n", 1);
        File::shouldReceive('put')->never();

        $this->artisan('shikimori:build-fixture')
            ->expectsOutputToContain('Insufficient dump data: selected 1/250 titles; missing scenarios:')
            ->assertFailed();

        Log::shouldHaveReceived('error')->once();
        $this->assertLockReleased();
    }

    #[Test]
    public function it_does_not_use_uncommitted_rows_to_satisfy_fixture_requirements(): void
    {
        $committed = "{\"id\":\"999999999\"}\n";
        $this->saveDump($committed, 1);
        file_put_contents($this->disk->path(self::ANIME_PATH), $this->curatedAnime(), FILE_APPEND);
        File::shouldReceive('put')->never();

        $this->artisan('shikimori:build-fixture')
            ->expectsOutputToContain('Insufficient dump data: selected 1/250 titles; missing scenarios:')
            ->assertFailed();
    }

    #[Test]
    public function it_requires_edge_case_coverage_even_when_the_dump_contains_enough_titles(): void
    {
        $lines = array_filter(explode("\n", trim($this->curatedAnime())), static function (string $line): bool {
            return (json_decode($line, true, 512, JSON_THROW_ON_ERROR)['kind'] ?? null) !== 'pv';
        });
        self::assertGreaterThanOrEqual(250, count($lines));
        $this->saveDump(implode("\n", $lines)."\n", count($lines));
        File::shouldReceive('put')->never();

        $this->artisan('shikimori:build-fixture')
            ->expectsOutputToContain('missing scenarios: pv.')
            ->assertFailed();
    }

    #[Test]
    public function it_validates_genres_before_writing_the_anime_fixture(): void
    {
        $this->saveCuratedDump();
        $this->disk->put(self::GENRES_PATH, "{invalid json}\n");
        File::shouldReceive('put')->never();

        $this->artisan('shikimori:build-fixture')
            ->expectsOutput('Failed to build Shikimori fixture: Syntax error')
            ->assertFailed();
        $this->assertLockReleased();
    }

    #[Test]
    public function it_rejects_an_empty_genres_dump_before_writing_either_fixture(): void
    {
        $this->saveCuratedDump();
        $this->disk->put(self::GENRES_PATH, '');
        File::shouldReceive('put')->never();

        $this->artisan('shikimori:build-fixture')
            ->expectsOutput('Failed to build Shikimori fixture: Insufficient dump data: genres are empty. Run shikimori:dump again.')
            ->assertFailed();
        $this->assertLockReleased();
    }


    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = Storage::fake('local');
        Log::spy();
    }


    private function saveDump(string $animes, int $titles): void
    {
        $this->disk->put(self::ANIME_PATH, $animes);
        $this->disk->put(self::GENRES_PATH, "{\"id\":\"10\",\"name\":\"Action\"}\n");
        $this->disk->put(self::CHECKPOINT_PATH, json_encode([
            'version' => 1, 'page' => 1, 'bytes' => strlen($animes), 'titles' => $titles,
        ], JSON_THROW_ON_ERROR));
    }

    private function saveCuratedDump(): string
    {
        $animes = $this->curatedAnime();
        $this->saveDump($animes, substr_count($animes, "\n"));

        return $animes;
    }

    private function curatedAnime(): string
    {
        return file_get_contents(base_path('static/Fixtures/Shikimori/animes.ndjson'));
    }

    private function assertLockReleased(): void
    {
        $lock = fopen($this->disk->path(self::LOCK_PATH), 'c+b');

        try {
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        } finally {
            fclose($lock);
        }
    }
}
