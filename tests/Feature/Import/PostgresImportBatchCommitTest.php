<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\DTOs\ImportBatch;
use App\Application\Import\DTOs\ImportCounters;
use App\Application\Import\DTOs\ImportRejectionData;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Import\Enums\RejectionReason;
use App\Infrastructure\Import\Locks\PostgresImportRunLock;
use App\Infrastructure\Persistence\Eloquent\Import\EloquentImportRunRecorder;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\StartsImportRuns;
use Tests\TestCase;

#[Group('pgsql')]
final class PostgresImportBatchCommitTest extends TestCase
{
    use StartsImportRuns;


    private string $previousConnection;
    private string $connectionName;
    private string $schemaName;
    private Connection $baseConnection;


    #[Test]
    public function a_new_owner_waits_for_the_old_transaction_and_reads_its_committed_checkpoint(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $batch = new ImportBatch(new ImportCounters(fetched: 1, accepted: 1), 1, ['1'], []);
        $process = null;
        $pipes = [];
        $peer = DB::connectUsing($this->connectionName.'_observer', DB::connection()->getConfig());

        try {
            $recorder->commit($run, $batch, function () use (&$process, &$pipes, $peer): void {
                DB::table('saved_candidates')->insert(['external_id' => '1']);
                $process = proc_open(
                    [PHP_BINARY, base_path('tests/Support/ResumeImportRun.php')],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes,
                    base_path(),
                );
                self::assertIsResource($process);
                fwrite($pipes[0], json_encode(DB::connection()->getConfig(), JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                $pid = (int) fgets($pipes[1]);
                self::assertGreaterThan(0, $pid);
                $blocked = false;

                for ($attempt = 0; $attempt < 200; $attempt++) {
                    $activity = $peer->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$pid]);
                    if (($activity->wait_event_type ?? null) === 'Lock') {
                        $blocked = true;
                        break;
                    }
                    usleep(10_000);
                }

                self::assertTrue($blocked, 'The new owner must wait for the batch row lock.');
                self::assertSame(0, (int) $peer->table('import_runs')->value('checkpoint'));
                self::assertSame(0, $peer->table('saved_candidates')->count());
            });

            $result = json_decode(trim(stream_get_contents($pipes[1])), true, 512, JSON_THROW_ON_ERROR);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            $process = null;
            self::assertSame(1, $result['checkpoint']);
            self::assertNotSame($run->ownerToken, $result['owner_token']);
            $this->assertDatabaseCount('saved_candidates', 1);
            $this->assertDatabaseCount('import_processed_titles', 1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no longer owned');
            $recorder->commit($run, $batch, fn () => DB::table('saved_candidates')->insert(['external_id' => '1']));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            DB::purge($this->connectionName.'_observer');
        }
    }

    #[Test]
    public function an_error_after_sink_writes_rolls_back_the_entire_postgresql_batch(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $batch = new ImportBatch(
            new ImportCounters(fetched: 1, rejected: 1), 1, ['1'],
            [new ImportRejectionData('1', RejectionReason::NO_TITLE)],
        );

        try {
            $recorder->commit($run, $batch, function (): void {
                DB::table('saved_candidates')->insert(['external_id' => '1']);
                throw new RuntimeException('Simulated lock loss after sink write');
            });
            self::fail('The batch must be rolled back.');
        } catch (RuntimeException $exception) {
            self::assertSame('Simulated lock loss after sink write', $exception->getMessage());
        }

        $this->assertDatabaseCount('saved_candidates', 0);
        $this->assertDatabaseCount('import_processed_titles', 0);
        $this->assertDatabaseCount('import_rejections', 0);
        $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'rejected' => 0]);
    }


    #[Test]
    public function terminating_the_real_lock_session_after_sink_writes_rolls_back_the_batch(): void
    {
        $lock = new PostgresImportRunLock;
        $recorder = new EloquentImportRunRecorder;

        try {
            $lock->synchronized(ProviderSource::Mock, ProviderDataSource::Api, function () use ($lock, $recorder): void {
                $run = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null, $lock->assertOwned(...));
                $batch = new ImportBatch(new ImportCounters(fetched: 1, accepted: 1), 1, ['1'], []);
                $recorder->commit($run, $batch, function () use ($lock): void {
                    DB::table('saved_candidates')->insert(['external_id' => '1']);
                    foreach (DB::getConnections() as $name => $connection) {
                        if (str_starts_with($name, 'import_lock_')) {
                            $pid = $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
                            self::assertTrue($this->baseConnection->selectOne('SELECT pg_terminate_backend(?) AS stopped', [$pid])->stopped);
                            $lock->assertOwned();
                            self::fail('A terminated lock session must fail ownership validation.');
                        }
                    }
                    self::fail('The dedicated lock connection must exist.');
                });
            });
            self::fail('The terminated lock must stop the import.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('lock session was lost', $exception->getMessage());
        }

        $this->assertDatabaseCount('saved_candidates', 0);
        $this->assertDatabaseCount('import_processed_titles', 0);
        $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'accepted' => 0]);
    }


    #[Test]
    #[DataProvider('lifecycleLockLosses')]
    public function losing_the_real_lock_rolls_back_lifecycle_changes(string $operation, bool $beforeMutation): void
    {
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        if ($operation !== 'start') {
            DB::table('import_runs')->where('id', $run->runId)->update([
                'status' => $operation === 'completed' ? 'completed' : 'failed',
                'finished_at' => now(),
            ]);
        }
        DB::table('import_rejections')->insert([
            'import_run_id' => $run->runId,
            'external_id' => 'unconfirmed',
            'reason' => 'malformed',
        ]);
        $before = DB::table('import_runs')->get()->all();
        $lock = new PostgresImportRunLock;
        $recorder = new EloquentImportRunRecorder;
        $armed = false;
        $terminated = false;
        $terminate = function () use (&$terminated): void {
            foreach (DB::getConnections() as $name => $connection) {
                if (str_starts_with($name, 'import_lock_')) {
                    $pid = (int) $connection->getPdo()->query('SELECT pg_backend_pid()')->fetchColumn();
                    $statement = $this->baseConnection->getPdo()->prepare('SELECT pg_terminate_backend(?)');
                    $statement->execute([$pid]);
                    self::assertTrue(in_array($statement->fetchColumn(), [true, 1, '1', 't'], true));
                    $terminated = true;

                    return;
                }
            }
            self::fail('The dedicated lock connection must exist.');
        };
        DB::listen(static function ($query) use (&$armed, $terminate): void {
            if ($armed && (str_starts_with($query->sql, 'update "import_runs"') || str_starts_with($query->sql, 'delete from "import_rejections"'))) {
                $armed = false;
                $terminate();
            }
        });

        try {
            $lock->synchronized(ProviderSource::Mock, ProviderDataSource::Api, function () use ($beforeMutation, &$armed, $terminate, $recorder, $lock, $operation): void {
                if ($beforeMutation) {
                    $terminate();
                } else {
                    $armed = true;
                }
                if ($operation === 'start') {
                    $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null, $lock->assertOwned(...));
                } else {
                    $recorder->resume(ProviderSource::Mock, ProviderDataSource::Api, null, $lock->assertOwned(...));
                }
                self::fail('A terminated lock must abort the lifecycle transaction.');
            });
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('lock session was lost', $exception->getMessage());
        } finally {
            $armed = false;
        }

        self::assertTrue($terminated);
        self::assertEquals($before, DB::table('import_runs')->get()->all());
        $this->assertDatabaseHas('import_rejections', ['import_run_id' => $run->runId, 'external_id' => 'unconfirmed']);
    }

    public static function lifecycleLockLosses(): array
    {
        return [
            'start before mutation' => ['start', true],
            'start during mutation' => ['start', false],
            'resume before mutation' => ['resume', true],
            'resume during mutation' => ['resume', false],
            'completed before cleanup' => ['completed', true],
            'completed during cleanup' => ['completed', false],
        ];
    }


    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('This regression requires PostgreSQL transactions.');
        }

        $this->previousConnection = DB::getDefaultConnection();
        $this->baseConnection = DB::connection();
        $this->schemaName = 'import_batch_'.bin2hex(random_bytes(8));
        $this->connectionName = $this->schemaName;
        $this->baseConnection->statement('CREATE SCHEMA '.$this->schemaName);
        $config = $this->baseConnection->getConfig();
        $config['search_path'] = $this->schemaName.', public';
        $config['name'] = $this->connectionName;
        config([
            'database.connections.'.$this->connectionName => $config,
            'database.default' => $this->connectionName,
        ]);

        foreach ([
            '2026_09_24_000001_create_import_runs_table.php',
            '2026_09_24_000002_create_import_rejections_table.php',
            '2026_10_22_105000_add_snapshot_id_to_import_runs_table.php',
            '2026_10_22_110000_create_import_processed_titles_table.php',
            '2026_10_29_090000_add_owner_token_to_import_runs.php',
        ] as $name) {
            (require database_path('migrations/'.$name))->up();
        }

        Schema::create('saved_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id');
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->schemaName)) {
            DB::purge($this->connectionName);
            config(['database.default' => $this->previousConnection]);
            $this->baseConnection->statement('DROP SCHEMA '.$this->schemaName.' CASCADE');
        }

        parent::tearDown();
    }
}
