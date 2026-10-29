<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\DTOs\ImportBatch;
use App\Application\Import\DTOs\ImportCounters;
use App\Application\Import\DTOs\ImportRejectionData;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\Enums\RejectionReason;
use App\Infrastructure\Import\Sinks\LogCandidateSink;
use App\Infrastructure\Persistence\Eloquent\Import\EloquentImportRunRecorder;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class ImportBatchCommitTest extends TestCase
{
    #[Test]
    public function it_commits_sink_rows_rejections_identities_and_checkpoint_together(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        $batch = new ImportBatch(
            new ImportCounters(fetched: 2, accepted: 1, rejected: 1), 2, ['1', '2'],
            [new ImportRejectionData('2', RejectionReason::NO_TITLE)],
        );

        $saved = $recorder->commit($run, $batch, fn () => DB::table('saved_candidates')->insert(['external_id' => '1']), true);

        self::assertSame(2, $saved->checkpoint);
        $this->assertDatabaseHas('import_runs', ['checkpoint' => 2, 'accepted' => 1, 'rejected' => 1, 'status' => 'completed']);
        $this->assertDatabaseCount('saved_candidates', 1);
        $this->assertDatabaseCount('import_processed_titles', 2);
        $this->assertDatabaseHas('import_rejections', ['external_id' => '2']);
    }

    #[Test]
    public function it_rolls_back_the_sink_when_ownership_is_lost_after_its_write(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);

        try {
            $recorder->commit($run, $this->rejectedBatch(), function (): void {
                DB::table('saved_candidates')->insert(['external_id' => '1']);
                throw new RuntimeException('Simulated ownership loss after flush');
            });
            self::fail('Ownership loss must roll back the batch.');
        } catch (RuntimeException $exception) {
            self::assertSame('Simulated ownership loss after flush', $exception->getMessage());
        }

        $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'rejected' => 0]);
        $this->assertDatabaseCount('saved_candidates', 0);
        $this->assertDatabaseCount('import_processed_titles', 0);
        $this->assertDatabaseCount('import_rejections', 0);
    }

    #[Test]
    public function it_rolls_back_sink_rows_and_rejections_when_recording_identities_fails(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            self::markTestSkipped('SQLite fault injection.');
        }

        $recorder = app(EloquentImportRunRecorder::class);
        $run = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        DB::unprepared("CREATE TRIGGER fail_identity BEFORE INSERT ON import_processed_titles BEGIN SELECT RAISE(ABORT, 'Simulated journal failure'); END");

        try {
            $recorder->commit($run, $this->rejectedBatch(), fn () => DB::table('saved_candidates')->insert(['external_id' => '1']));
            self::fail('A journal failure must roll back the batch.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('Simulated journal failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'rejected' => 0]);
        $this->assertDatabaseCount('saved_candidates', 0);
        $this->assertDatabaseCount('import_processed_titles', 0);
        $this->assertDatabaseCount('import_rejections', 0);
    }

    #[Test]
    public function it_fences_a_previous_owner_before_any_sink_write(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $old = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        $current = $recorder->resume(ProviderSource::Mock, ProviderDataSource::Api, null);
        self::assertNotSame($old->ownerToken, $current->ownerToken);

        try {
            $recorder->commit($old, $this->rejectedBatch(), fn () => DB::table('saved_candidates')->insert(['external_id' => '1']));
            self::fail('The previous owner must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('no longer owned', $exception->getMessage());
        }

        try {
            $recorder->fail($old, 'Stale worker failure');
            self::fail('The previous owner must not change the run status.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('no longer owned', $exception->getMessage());
        }

        $this->assertDatabaseCount('saved_candidates', 0);
        $this->assertDatabaseHas('import_runs', ['owner_token' => $current->ownerToken, 'status' => 'running', 'checkpoint' => 0]);
        $recorder->commit($current, $this->rejectedBatch(), static fn () => null, true);
        $this->assertDatabaseCount('import_rejections', 1);
    }

    #[Test]
    public function it_rejects_a_repeated_batch_before_calling_the_sink(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        $recorder->commit($run, $this->rejectedBatch(), static fn () => null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checkpoint is stale');

        try {
            $recorder->commit($run, $this->rejectedBatch(), fn () => DB::table('saved_candidates')->insert(['external_id' => '1']));
        } finally {
            $this->assertDatabaseCount('saved_candidates', 0);
            $this->assertDatabaseCount('import_rejections', 1);
            $this->assertDatabaseCount('import_processed_titles', 1);
        }
    }

    #[Test]
    public function it_preserves_a_committed_checkpoint_when_failure_uses_an_older_local_checkpoint(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        $recorder->commit($run, $this->rejectedBatch(), static fn () => null);
        $recorder->fail($run, 'Commit acknowledgement was lost');

        $this->assertDatabaseHas('import_runs', ['checkpoint' => 1, 'rejected' => 1, 'status' => 'failed']);
        $resumed = $recorder->resume(ProviderSource::Mock, ProviderDataSource::Api, null);
        self::assertSame(1, $resumed->checkpoint);
    }

    #[Test]
    public function it_discards_legacy_unconfirmed_rejections_when_claiming_a_run(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        DB::table('import_rejections')->insert(['import_run_id' => $run->runId, 'external_id' => '1', 'reason' => 'no_title']);
        $recorder->resume(ProviderSource::Mock, ProviderDataSource::Api, null);

        $this->assertDatabaseCount('import_rejections', 0);
        $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'rejected' => 0]);
    }

    #[Test]
    public function it_emits_candidate_logs_only_after_a_successful_batch_commit(): void
    {
        Log::shouldReceive('info')->once()->with('Import candidate accepted.', \Mockery::type('array'));
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        $candidate = app(ProviderTitleToCandidateMapper::class)->map((new ShikimoriTitleMapper)->map([
            'id' => '1', 'russian' => 'Тайтл', 'status' => 'released',
        ]));
        $sink = new LogCandidateSink;
        $batch = new ImportBatch(new ImportCounters(fetched: 1, accepted: 1), 1, ['1'], []);

        $sink->consume($candidate, FilterDecision::accept());
        try {
            $recorder->commit($run, $batch, function () use ($sink): void {
                $sink->flush();
                throw new RuntimeException('Simulated failure after flush');
            });
        } catch (RuntimeException) {
            $sink->abort();
        }

        $sink->consume($candidate, FilterDecision::accept());
        $recorder->commit($run, $batch, fn () => $sink->finish(), true);
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

        Schema::create('saved_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id');
        });
    }


    private function rejectedBatch(): ImportBatch
    {
        return new ImportBatch(
            new ImportCounters(fetched: 1, rejected: 1), 1, ['1'],
            [new ImportRejectionData('1', RejectionReason::NO_TITLE)],
        );
    }
}
