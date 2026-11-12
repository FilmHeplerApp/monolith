<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Infrastructure\Persistence\Eloquent\Import\EloquentImportRunRecorder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\StartsImportRuns;
use Tests\TestCase;

final class ImportRunLifecycleLockTest extends TestCase
{
    use StartsImportRuns;


    #[Test]
    public function it_builds_each_importer_with_its_own_lock_and_one_shared_recorder(): void
    {
        $first = app(ImportTitlesFromProvider::class);
        $second = app(ImportTitlesFromProvider::class);
        $locks = [];

        foreach ([$first, $second] as $importer) {
            $recorder = new ReflectionProperty($importer, 'recorder')->getValue($importer);
            $streams = new ReflectionProperty($importer, 'streams')->getValue($importer);

            self::assertSame($recorder, new ReflectionProperty($streams, 'recorder')->getValue($streams));
            $locks[] = new ReflectionProperty($importer, 'lock')->getValue($importer);
        }

        self::assertNotSame($locks[0], $locks[1]);
    }

    #[Test]
    public function it_executes_an_import_with_the_container_resolved_lock(): void
    {
        $result = app(ImportTitlesFromProvider::class)->execute(ProviderSource::Mock, ProviderDataSource::Api, 1);

        self::assertSame(1, $result->checkpoint);
        $this->assertDatabaseHas('import_runs', ['id' => $result->runId, 'status' => 'completed']);
    }

    #[Test]
    public function it_authorizes_start_after_its_changes_inside_the_transaction(): void
    {
        $peer = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $before = DB::table('import_runs')->where('id', $peer->runId)->first();

        try {
            new EloquentImportRunRecorder()->start(ProviderSource::Mock, ProviderDataSource::Api, null, function () use ($peer): void {
                self::assertSame(2, DB::table('import_runs')->count());
                self::assertSame('superseded', DB::table('import_runs')->where('id', $peer->runId)->value('status'));

                throw new RuntimeException('Lost lifecycle lock.');
            });
            self::fail('A worker without its lock must not start or supersede a run.');
        } catch (RuntimeException $exception) {
            self::assertSame('Lost lifecycle lock.', $exception->getMessage());
        }

        $this->assertDatabaseCount('import_runs', 1);
        self::assertEquals($before, DB::table('import_runs')->where('id', $peer->runId)->first());
    }

    #[Test]
    #[DataProvider('resumedRuns')]
    public function it_authorizes_resume_after_its_changes_and_rolls_them_back(string $status): void
    {
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        DB::table('import_runs')->where('id', $run->runId)->update([
            'status' => $status,
            'finished_at' => now(),
            'error_message' => $status === 'failed' ? 'Previous failure' : null,
        ]);
        DB::table('import_rejections')->insert([
            'import_run_id' => $run->runId,
            'external_id' => 'unconfirmed',
            'reason' => 'malformed',
        ]);
        $before = DB::table('import_runs')->where('id', $run->runId)->first();

        try {
            new EloquentImportRunRecorder()->resume(ProviderSource::Mock, ProviderDataSource::Api, null, function () use ($run, $status): void {
                self::assertSame(0, DB::table('import_rejections')->where('import_run_id', $run->runId)->count());
                self::assertSame($status === 'failed' ? 'running' : 'completed', DB::table('import_runs')->where('id', $run->runId)->value('status'));

                throw new RuntimeException('Lost lifecycle lock.');
            });
            self::fail('A worker without its lock must not resume a run.');
        } catch (RuntimeException $exception) {
            self::assertSame('Lost lifecycle lock.', $exception->getMessage());
        }

        self::assertEquals($before, DB::table('import_runs')->where('id', $run->runId)->first());
        $this->assertDatabaseHas('import_rejections', ['import_run_id' => $run->runId, 'external_id' => 'unconfirmed']);
    }

    public static function resumedRuns(): array
    {
        return ['failed run' => ['failed'], 'completed run' => ['completed']];
    }

    #[Test]
    public function it_authorizes_resume_when_no_run_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lost lifecycle lock.');

        new EloquentImportRunRecorder()->resume(ProviderSource::Mock, ProviderDataSource::Api, null, static fn () => throw new RuntimeException('Lost lifecycle lock.'));
    }


    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_24_000001_create_import_runs_table.php',
            '2026_09_24_000002_create_import_rejections_table.php',
            '2026_10_22_105000_add_snapshot_id_to_import_runs_table.php',
            '2026_10_22_110000_create_import_processed_titles_table.php',
            '2026_10_29_090000_add_owner_token_to_import_runs.php',
        ] as $name) {
            (require database_path('migrations/'.$name))->up();
        }
    }
}
