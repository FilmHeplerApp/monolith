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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\ProviderRecords;
use Tests\Support\StartsImportRuns;
use Tests\TestCase;
use Throwable;

final class ImportBatchCommitTest extends TestCase
{
    use StartsImportRuns;


    #[Test]
    public function it_refuses_to_attach_a_snapshot_to_a_legacy_nonzero_checkpoint(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Dump, null);
        $run = $recorder->commit($run, $this->rejectedBatch(), static fn () => null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy import checkpoint has no dump snapshot.');
        $recorder->bindSnapshot($run, 'new-snapshot');
    }

    #[Test]
    public function it_completes_an_empty_final_batch_without_repeating_confirmed_results(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $run = $recorder->commit($run, $this->rejectedBatch(), static fn () => null);
        $finished = false;

        $saved = $recorder->commit($run, new ImportBatch($run->counters, $run->checkpoint, [], []), function () use (&$finished): void {
            $finished = true;
        }, true);

        self::assertTrue($finished);
        self::assertTrue($saved->completed);
        self::assertSame(1, $saved->checkpoint);
        $this->assertDatabaseHas('import_runs', ['status' => 'completed', 'checkpoint' => 1, 'rejected' => 1]);
        $this->assertDatabaseCount('import_processed_titles', 1);
        $this->assertDatabaseCount('import_rejections', 1);
    }

    #[Test]
    public function it_rolls_back_failed_finalization_of_an_empty_batch_and_preserves_the_previous_checkpoint(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $run = $recorder->commit($run, $this->rejectedBatch(), static fn () => null);

        try {
            $recorder->commit($run, new ImportBatch($run->counters, $run->checkpoint, [], []), function (): void {
                DB::table('saved_candidates')->insert(['external_id' => 'unexpected-finalization-write']);
                throw new RuntimeException('Finalization failed');
            }, true);
            self::fail('Failed finalization must roll back.');
        } catch (RuntimeException $exception) {
            self::assertSame('Finalization failed', $exception->getMessage());
        }

        $this->assertDatabaseCount('saved_candidates', 0);
        $this->assertDatabaseHas('import_runs', ['status' => 'running', 'checkpoint' => 1, 'rejected' => 1, 'finished_at' => null]);
        $this->assertDatabaseCount('import_processed_titles', 1);
        $this->assertDatabaseCount('import_rejections', 1);
    }

    #[Test]
    #[DataProvider('invalidBatches')]
    public function it_rejects_inconsistent_batches_before_writing_sink_data(ImportBatch $batch): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $called = false;

        try {
            $recorder->commit($run, $batch, function () use (&$called): void {
                $called = true;
                DB::table('saved_candidates')->insert(['external_id' => 'unexpected']);
            });
            self::fail('An inconsistent batch must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertFalse($called);
        }

        $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'fetched' => 0, 'status' => 'running']);
        $this->assertDatabaseCount('saved_candidates', 0);
        $this->assertDatabaseCount('import_processed_titles', 0);
        $this->assertDatabaseCount('import_rejections', 0);
    }

    public static function invalidBatches(): array
    {
        return [
            'duplicate ids' => [new ImportBatch(new ImportCounters(fetched: 2, accepted: 2), 2, ['1', '1'], [])],
            'checkpoint does not match id count' => [new ImportBatch(new ImportCounters(fetched: 2, accepted: 2), 2, ['1'], [])],
            'fetched does not match checkpoint' => [new ImportBatch(new ImportCounters(fetched: 1, accepted: 1), 2, ['1', '2'], [])],
            'missing rejection details' => [new ImportBatch(new ImportCounters(fetched: 1, rejected: 1), 1, ['1'], [])],
            'foreign rejection id' => [new ImportBatch(new ImportCounters(fetched: 1, rejected: 1), 1, ['1'], [new ImportRejectionData('other', RejectionReason::NO_TITLE)])],
            'duplicate rejection id' => [new ImportBatch(new ImportCounters(fetched: 2, rejected: 2), 2, ['1', '2'], [
                new ImportRejectionData('1', RejectionReason::NO_TITLE),
                new ImportRejectionData('1', RejectionReason::MALFORMED),
            ])],
        ];
    }

    #[Test]
    public function it_rejects_an_already_committed_identity_in_a_new_batch(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $run = $recorder->commit($run, $this->rejectedBatch(), static fn () => null);
        $called = false;

        try {
            $recorder->commit($run, new ImportBatch(new ImportCounters(fetched: 2, accepted: 1, rejected: 1), 2, ['1'], []), function () use (&$called): void {
                $called = true;
            });
            self::fail('An identity must not be counted twice.');
        } catch (RuntimeException $exception) {
            self::assertSame('Import identity was already committed.', $exception->getMessage());
            self::assertFalse($called);
        }

        $this->assertDatabaseHas('import_runs', ['checkpoint' => 1, 'rejected' => 1, 'accepted' => 0]);
        $this->assertDatabaseCount('import_processed_titles', 1);
        $this->assertDatabaseCount('import_rejections', 1);
    }

    #[Test]
    public function it_preserves_the_original_limit_when_resume_omits_it(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, 10);
        $recorder->fail($run, 'Interrupted');

        $resumed = $this->resumeRun(ProviderSource::Mock, ProviderDataSource::Api, null);

        self::assertSame($run->runId, $resumed->runId);
        self::assertSame(10, $resumed->limit);
        self::assertNotSame($run->ownerToken, $resumed->ownerToken);
        $this->assertDatabaseHas('import_runs', ['status' => 'running', 'finished_at' => null, 'error_message' => null]);
    }

    #[Test]
    public function it_rejects_a_changed_resume_limit_without_claiming_the_run(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, 10);
        $recorder->fail($run, 'Interrupted');

        try {
            $this->resumeRun(ProviderSource::Mock, ProviderDataSource::Api, 20);
            self::fail('The original limit must be preserved.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('does not match', $exception->getMessage());
        }

        $this->assertDatabaseHas('import_runs', ['owner_token' => $run->ownerToken, 'status' => 'failed', 'limit' => 10, 'error_message' => 'Interrupted']);
    }

    #[Test]
    public function it_resumes_the_latest_run_for_the_requested_provider_and_source_only(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, 1);
        $expected = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, 2);
        $foreignProvider = $this->startRun(ProviderSource::Shikimori, ProviderDataSource::Api, 3);
        $foreignSource = $this->startRun(ProviderSource::Mock, ProviderDataSource::Dump, 4);

        $resumed = $this->resumeRun(ProviderSource::Mock, ProviderDataSource::Api, null);

        self::assertSame($expected->runId, $resumed->runId);
        self::assertSame(2, $resumed->limit);
        $this->assertDatabaseHas('import_runs', ['id' => $foreignProvider->runId, 'owner_token' => $foreignProvider->ownerToken]);
        $this->assertDatabaseHas('import_runs', ['id' => $foreignSource->runId, 'owner_token' => $foreignSource->ownerToken]);
    }

    #[Test]
    public function it_refuses_resume_when_a_confirmed_rejection_is_missing(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $run = $recorder->commit($run, $this->rejectedBatch(), static fn () => null);
        DB::table('import_rejections')->where('import_run_id', $run->runId)->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy rejection report is inconsistent.');
        $this->resumeRun(ProviderSource::Mock, ProviderDataSource::Api, null);
    }

    #[Test]
    public function it_commits_sink_rows_rejections_identities_and_checkpoint_together(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
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
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);

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
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
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
        $old = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $current = $this->resumeRun(ProviderSource::Mock, ProviderDataSource::Api, null);
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
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
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
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $recorder->commit($run, $this->rejectedBatch(), static fn () => null);
        $recorder->fail($run, 'Commit acknowledgement was lost');

        $this->assertDatabaseHas('import_runs', ['checkpoint' => 1, 'rejected' => 1, 'status' => 'failed']);
        $resumed = $this->resumeRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        self::assertSame(1, $resumed->checkpoint);
    }

    #[Test]
    public function it_discards_legacy_unconfirmed_rejections_when_claiming_a_run(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        DB::table('import_rejections')->insert(['import_run_id' => $run->runId, 'external_id' => '1', 'reason' => 'no_title']);
        $this->resumeRun(ProviderSource::Mock, ProviderDataSource::Api, null);

        $this->assertDatabaseCount('import_rejections', 0);
        $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'rejected' => 0]);
    }

    #[Test]
    public function it_emits_candidate_logs_only_after_a_successful_batch_commit(): void
    {
        Log::shouldReceive('info')->once()->with('Import candidate accepted.', \Mockery::type('array'));
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $candidate = app(ProviderTitleToCandidateMapper::class)->map((new ShikimoriTitleMapper)->map(ProviderRecords::shikimori([
            'id' => '1', 'russian' => 'Тайтл', 'status' => 'released',
        ])));
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


    #[Test]
    public function it_returns_the_committed_checkpoint_when_candidate_logging_fails_after_commit(): void
    {
        Log::shouldReceive('info')->twice()->andThrow(new RuntimeException('Logger down'));
        Log::shouldReceive('warning')->never();
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $this->startRun(ProviderSource::Mock, ProviderDataSource::Api, null);
        $sink = new LogCandidateSink;
        foreach (['1', '2'] as $id) {
            $sink->consume(app(ProviderTitleToCandidateMapper::class)->map((new ShikimoriTitleMapper)->map(ProviderRecords::shikimori([
                'id' => $id, 'russian' => 'Тайтл '.$id, 'status' => 'released',
            ]))), FilterDecision::accept());
        }

        $committed = $recorder->commit(
            $run,
            new ImportBatch(new ImportCounters(fetched: 2, accepted: 2), 2, ['1', '2'], []),
            fn () => $sink->flush(),
        );

        self::assertSame(2, $committed->checkpoint);
        $this->assertDatabaseHas('import_runs', ['id' => $run->runId, 'checkpoint' => 2, 'status' => 'running']);
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
