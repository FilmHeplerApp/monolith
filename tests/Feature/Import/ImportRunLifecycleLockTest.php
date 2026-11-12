<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Infrastructure\Persistence\Eloquent\Import\EloquentImportRunRecorder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\StartsImportRuns;
use Tests\TestCase;

final class ImportRunLifecycleLockTest extends TestCase
{
    use StartsImportRuns;


    /**
     * @throws ReflectionException
     */
    #[Test]
    public function it_shares_one_lock_and_recorder_within_each_importer(): void
    {
        $first = app(ImportTitlesFromProvider::class);
        $second = app(ImportTitlesFromProvider::class);
        $locks = [];

        foreach ([$first, $second] as $importer) {
            $lock = new ReflectionProperty($importer, 'lock')->getValue($importer);
            $recorder = new ReflectionProperty($importer, 'recorder')->getValue($importer);
            $streams = new ReflectionProperty($importer, 'streams')->getValue($importer);

            self::assertSame($lock, new ReflectionProperty($recorder, 'lock')->getValue($recorder));
            self::assertSame($recorder, new ReflectionProperty($streams, 'recorder')->getValue($streams));
            $locks[] = $lock;
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
    #[DataProvider('lostOwnershipChecks')]
    public function it_preserves_the_current_run_when_start_loses_ownership(int $failOn): void
    {
        $peer = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $before = DB::table('import_runs')->where('id', $peer->runId)->first();
        $recorder = $this->recorderLosingOwnership($failOn);

        try {
            $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
            self::fail('A worker without its lock must not start or supersede a run.');
        } catch (RuntimeException $exception) {
            self::assertSame('Lost lifecycle lock.', $exception->getMessage());
        }

        $this->assertDatabaseCount('import_runs', 1);
        self::assertEquals($before, DB::table('import_runs')->where('id', $peer->runId)->first());
    }

    public static function lostOwnershipChecks(): array
    {
        return ['before mutation' => [1], 'before transaction commit' => [2]];
    }

    #[Test]
    #[DataProvider('resumedRuns')]
    public function it_rolls_back_resume_and_rejection_cleanup_when_ownership_is_lost(string $status, int $failOn): void
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
        $recorder = $this->recorderLosingOwnership($failOn);

        try {
            $recorder->resume(ProviderSource::Mock, ProviderDataSource::Api, null);
            self::fail('A worker without its lock must not resume a run.');
        } catch (RuntimeException $exception) {
            self::assertSame('Lost lifecycle lock.', $exception->getMessage());
        }

        self::assertEquals($before, DB::table('import_runs')->where('id', $run->runId)->first());
        $this->assertDatabaseHas('import_rejections', ['import_run_id' => $run->runId, 'external_id' => 'unconfirmed']);
    }

    public static function resumedRuns(): array
    {
        return [
            'failed, lost before mutation' => ['failed', 1],
            'failed, lost after mutation' => ['failed', 2],
            'completed, lost before cleanup' => ['completed', 1],
            'completed, lost after cleanup' => ['completed', 2],
        ];
    }

    #[Test]
    #[DataProvider('lostOwnershipChecks')]
    public function it_checks_ownership_when_no_run_exists_to_resume(int $failOn): void
    {
        $recorder = $this->recorderLosingOwnership($failOn);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lost lifecycle lock.');

        $recorder->resume(ProviderSource::Mock, ProviderDataSource::Api, null);
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


    private function recorderLosingOwnership(int $failOn): EloquentImportRunRecorder
    {
        $checks = 0;
        $lock = $this->createMock(ImportRunLockContract::class);
        $lock->expects(self::exactly($failOn))->method('assertOwned')->willReturnCallback(static function () use (&$checks, $failOn): void {
            if (++$checks === $failOn) {
                throw new RuntimeException('Lost lifecycle lock.');
            }
        });

        return new EloquentImportRunRecorder($lock);
    }
}
