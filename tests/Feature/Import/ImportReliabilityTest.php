<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Contracts\ProviderImportContextFactoryContract;
use App\Application\Import\Contracts\ProviderTitleMapperContract;
use App\Application\Import\DTOs\ImportBatch;
use App\Application\Import\DTOs\ImportCounters;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ImportRunMode;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderUnavailableException;
use App\Application\Import\Services\ProviderImportContext;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\RejectionReason;
use App\Infrastructure\Import\Sinks\LogCandidateSink;
use App\Infrastructure\Persistence\Eloquent\Import\EloquentImportRunRecorder;
use App\Infrastructure\Persistence\Eloquent\Models\Import\ImportRejection;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CommittedShikimoriDump;
use Tests\Support\ProviderRecords;
use Tests\Support\SingleProviderClientFactory;
use Tests\TestCase;

final class ImportReliabilityTest extends TestCase
{
    private string $directory;


    #[Test]
    #[DataProvider('emptyAndExactBatches')]
    public function it_completes_empty_rejected_only_and_exact_batches(array $ids, bool $rejectAll): void
    {
        config()->set('import.checkpoint_every', 2);
        $records = iterator_to_array($this->client(...$ids)->fetchTitles(null));
        if ($rejectAll) {
            $records = array_map(static fn ($record) => new ProviderTitleRecord($record->source, $record->externalId, array_replace($record->payload, ['status' => 'unknown'])), $records);
        }
        $this->bindClient(new MockProviderClient($records));
        $sink = $this->sink();

        $result = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        self::assertSame(count($ids), $result->checkpoint);
        self::assertSame($rejectAll ? 0 : count($ids), $result->counters->accepted);
        self::assertSame($rejectAll ? count($ids) : 0, $result->counters->rejected);
        self::assertSame($rejectAll ? [] : $ids, $sink->saved);
        $this->assertDatabaseHas('import_runs', ['status' => 'completed']);
        $this->assertDatabaseCount('import_processed_titles', count($ids));
    }

    public static function emptyAndExactBatches(): array
    {
        return ['empty source' => [[], false], 'only rejected records' => [['1', '2'], true], 'exact batch size' => [['1', '2'], false]];
    }

    #[Test]
    #[DataProvider('nonPositiveValues')]
    public function it_rejects_invalid_limits_before_creating_a_run(int $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, limit: $value);
        } finally {
            $this->assertDatabaseCount('import_runs', 0);
        }
    }

    #[Test]
    #[DataProvider('nonPositiveValues')]
    public function it_rejects_invalid_checkpoint_intervals(int $value): void
    {
        config()->set('import.checkpoint_every', $value);
        $this->expectException(\InvalidArgumentException::class);
        $this->importer();
    }

    public static function nonPositiveValues(): array
    {
        return [[0], [-1]];
    }

    #[Test]
    public function it_preserves_the_original_failure_when_discarding_the_buffer_also_fails(): void
    {
        $original = new ProviderUnavailableException(ProviderSource::Mock, 'Original provider failure');
        $client = $this->client('1');
        $client->failWith($original);
        $this->bindClient($client);
        $sink = $this->createStub(CandidateSinkContract::class);
        $sink->method('abort')->willThrowException(new RuntimeException('Abort failed'));
        $this->app->instance(CandidateSinkContract::class, $sink);

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('The original failure must propagate.');
        } catch (ProviderUnavailableException $exception) {
            self::assertSame($original, $exception);
        }
        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 0, 'error_message' => 'Original provider failure']);
    }

    #[Test]
    #[DataProvider('nonFiniteRatings')]
    public function it_persists_non_finite_rating_rejections_without_losing_neighboring_records(float $rating, string $expected): void
    {
        config()->set('import.checkpoint_every', 3);
        $records = iterator_to_array($this->client('1', '2', '3')->fetchTitles(null));
        $client = new MockProviderClient(array_map(static fn (ProviderTitleRecord $record) => new ProviderTitleRecord(
            $record->source,
            $record->externalId,
            array_replace($record->payload, ['rating' => $record->externalId === '2' ? $rating : 8.0]),
        ), $records));
        $this->bindClient($client);
        $sink = $this->sink();

        $result = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        self::assertSame(3, $result->checkpoint);
        self::assertSame(2, $result->counters->accepted);
        self::assertSame(1, $result->counters->rejected);
        self::assertSame(['1', '3'], $sink->saved);
        $context = json_decode(DB::table('import_rejections')->where('external_id', '2')->value('context'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['rating' => $expected], $context);
        $this->assertDatabaseHas('import_rejections', ['external_id' => '2', 'reason' => 'malformed']);
        $this->assertDatabaseCount('import_processed_titles', 3);

        $sink = $this->sink();
        $resumed = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, resume: true);
        self::assertSame($result->runId, $resumed->runId);
        self::assertSame(1, $resumed->counters->rejected);
        self::assertSame([], $sink->saved);
        $this->assertDatabaseCount('import_rejections', 1);
    }

    public static function nonFiniteRatings(): array
    {
        return ['positive infinity' => [INF, 'INF'], 'negative infinity' => [-INF, '-INF'], 'not a number' => [NAN, 'NAN']];
    }

    #[Test]
    public function it_processes_the_same_identities_in_a_separate_run_without_resume(): void
    {
        $this->bindClient($this->client('1', '2'));
        $this->sink();
        $first = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
        $sink = $this->sink();

        $second = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        self::assertNotSame($first->runId, $second->runId);
        self::assertSame(ImportRunMode::New, $second->mode);
        self::assertSame(['1', '2'], $sink->saved);
        $this->assertDatabaseCount('import_runs', 2);
        $this->assertDatabaseCount('import_processed_titles', 4);
    }

    #[Test]
    #[DataProvider('rawSources')]
    public function it_commits_mapping_rejections_and_resumes_the_remaining_raw_records(ProviderDataSource $source): void
    {
        config()->set('import.checkpoint_every', 1);
        $rows = [
            [...$this->row('1'), 'status' => ['broken']],
            $this->row('2'),
            $this->row('3'),
        ];
        if ($source === ProviderDataSource::Api) {
            Sleep::fake();
            Http::fake(['*' => Http::response(['data' => ['animes' => $rows]])]);
        } else {
            $data = implode("\n", array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows))."\n";
            CommittedShikimoriDump::configure(CommittedShikimoriDump::create($this->directory, $data));
        }
        $this->sink(failFlush: 2);

        try {
            $this->importer()->execute(ProviderSource::Shikimori, $source);
            self::fail('The second batch must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Flush failed', $exception->getMessage());
        }

        $this->assertDatabaseHas('import_runs', ['checkpoint' => 1, 'rejected' => 1, 'status' => 'failed']);
        $this->assertDatabaseHas('import_rejections', ['external_id' => '1', 'reason' => 'malformed']);
        $sink = $this->sink();

        $result = $this->importer()->execute(ProviderSource::Shikimori, $source, resume: true);

        self::assertSame(ImportRunMode::Resumed, $result->mode);
        self::assertSame(3, $result->checkpoint);
        self::assertSame(2, $result->counters->accepted);
        self::assertSame(1, $result->counters->rejected);
        self::assertSame(['2', '3'], $sink->saved);
        $this->assertDatabaseCount('import_processed_titles', 3);
        $this->assertDatabaseCount('import_rejections', 1);
    }

    public static function rawSources(): array
    {
        return [[ProviderDataSource::Api], [ProviderDataSource::Dump]];
    }

    #[Test]
    public function it_counts_a_repeated_api_record_once_even_when_mapping_is_rejected(): void
    {
        Sleep::fake();
        $rejected = [...$this->row('1'), 'status' => ['broken']];
        Http::fake(['*' => Http::response(['data' => ['animes' => [$rejected, $rejected, $this->row('2')]]])]);
        $sink = $this->sink();

        $result = $this->importer()->execute(ProviderSource::Shikimori, ProviderDataSource::Api, limit: 2);

        self::assertSame(2, $result->checkpoint);
        self::assertSame(1, $result->counters->accepted);
        self::assertSame(1, $result->counters->rejected);
        self::assertSame(['2'], $sink->saved);
        $this->assertDatabaseCount('import_processed_titles', 2);
        $this->assertDatabaseCount('import_rejections', 1);
    }

    #[Test]
    public function it_does_not_turn_mapper_programming_errors_into_record_rejections(): void
    {
        $mapper = $this->createStub(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Mock);
        $original = new \LogicException('Mapper programming error');
        $mapper->method('map')->willThrowException($original);
        $contexts = $this->createStub(ProviderImportContextFactoryContract::class);
        $contexts->method('create')->willReturn(new ProviderImportContext($this->client('1'), $mapper));
        $this->app->instance(ProviderImportContextFactoryContract::class, $contexts);
        $this->sink();

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('Programming errors must stop the run.');
        } catch (\LogicException $exception) {
            self::assertSame($original, $exception);
        }

        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 0]);
        $this->assertDatabaseCount('import_rejections', 0);
        $this->assertDatabaseCount('import_processed_titles', 0);
    }

    #[Test]
    #[DataProvider('changedIdentities')]
    public function it_stops_when_a_mapper_changes_the_record_identity(ProviderSource $provider, string $externalId): void
    {
        $mapper = $this->createStub(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Mock);
        $title = (new ShikimoriTitleMapper)->map(ProviderRecords::shikimori($this->row('1')));
        $mapper->method('map')->willReturn(new ProviderTitle(...array_replace(get_object_vars($title), [
            'source' => $provider,
            'externalId' => $externalId,
        ])));
        $contexts = $this->createStub(ProviderImportContextFactoryContract::class);
        $contexts->method('create')->willReturn(new ProviderImportContext($this->client('1'), $mapper));
        $this->app->instance(ProviderImportContextFactoryContract::class, $contexts);
        $this->sink();

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('A mapper must preserve identity.');
        } catch (RuntimeException $exception) {
            self::assertSame('Mapped title identity does not match its raw record.', $exception->getMessage());
        }

        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 0]);
        $this->assertDatabaseCount('import_processed_titles', 0);
        $this->assertDatabaseCount('import_rejections', 0);
    }

    public static function changedIdentities(): array
    {
        return [
            'only external id changed' => [ProviderSource::Mock, 'other'],
            'only provider changed' => [ProviderSource::Shikimori, '1'],
        ];
    }

    #[Test]
    public function it_rejects_a_foreign_record_before_calling_the_mapper(): void
    {
        $record = ProviderRecords::shikimori($this->row('1'));
        $client = $this->createStub(ProviderClientContract::class);
        $client->method('source')->willReturn(ProviderSource::Mock);
        $client->method('fetchTitles')->willReturnCallback(static function () use ($record): \Generator {
            yield $record;
        });
        $mapper = $this->createMock(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Mock);
        $mapper->expects(self::never())->method('map');
        $contexts = $this->createStub(ProviderImportContextFactoryContract::class);
        $contexts->method('create')->willReturn(new ProviderImportContext($client, $mapper));
        $this->app->instance(ProviderImportContextFactoryContract::class, $contexts);
        $sink = $this->sink();

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('Foreign records must stop the run.');
        } catch (RuntimeException $exception) {
            self::assertSame('Import record does not match the provider of its context.', $exception->getMessage());
        }

        self::assertSame([], $sink->saved);
        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 0]);
        $this->assertDatabaseCount('import_processed_titles', 0);
    }

    #[Test]
    public function it_stops_before_reading_when_the_context_belongs_to_another_provider(): void
    {
        $client = $this->createMock(ProviderClientContract::class);
        $client->method('source')->willReturn(ProviderSource::Mock);
        $client->expects(self::never())->method('fetchTitles');
        $mapper = $this->createStub(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Mock);
        $contexts = $this->createStub(ProviderImportContextFactoryContract::class);
        $contexts->method('create')->willReturn(new ProviderImportContext($client, $mapper));
        $this->app->instance(ProviderImportContextFactoryContract::class, $contexts);
        $this->sink();

        try {
            $this->importer()->execute(ProviderSource::Shikimori, ProviderDataSource::Api);
            self::fail('A context of another provider must stop the run.');
        } catch (RuntimeException $exception) {
            self::assertSame('Import context does not match the requested provider.', $exception->getMessage());
        }

        $this->assertDatabaseCount('import_runs', 0);
    }

    #[Test]
    public function it_imports_a_record_with_unparseable_optional_fields_as_unknown_data(): void
    {
        Sleep::fake();
        $broken = [
            ...$this->row('1'),
            'genres' => 'broken list',
            'studios' => [42],
            'scoresStats' => ['count' => 3],
            'score' => 'abc',
            'duration' => ['24'],
            'kind' => ['pv'],
            'airedOn' => ['year' => ['1960']],
        ];
        Http::fake(['*' => Http::response(['data' => ['animes' => [$broken, $this->row('2')]]])]);
        $sink = $this->sink();

        $result = $this->importer()->execute(ProviderSource::Shikimori, ProviderDataSource::Api);

        self::assertSame(2, $result->counters->accepted);
        self::assertSame(0, $result->counters->rejected);
        self::assertSame(['1', '2'], $sink->saved);
        $this->assertDatabaseCount('import_rejections', 0);
        $this->assertDatabaseHas('import_runs', ['status' => 'completed', 'checkpoint' => 2]);
    }

    #[Test]
    public function it_stores_a_non_finite_rating_as_one_malformed_rejection_and_keeps_its_neighbours(): void
    {
        $mapper = $this->createStub(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Mock);
        $mapper->method('map')->willReturnCallback(function (ProviderTitleRecord $record): ProviderTitle {
            $title = (new ShikimoriTitleMapper)->map(ProviderRecords::shikimori($this->row($record->externalId)));

            return new ProviderTitle(...array_replace(get_object_vars($title), [
                'source' => ProviderSource::Mock,
                'rating' => $record->externalId === '2' ? INF : 8.0,
            ]));
        });
        $contexts = $this->createStub(ProviderImportContextFactoryContract::class);
        $contexts->method('create')->willReturn(new ProviderImportContext($this->client('1', '2', '3'), $mapper));
        $this->app->instance(ProviderImportContextFactoryContract::class, $contexts);
        config()->set('import.checkpoint_every', 2);
        $sink = $this->sink();

        $result = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        self::assertSame(3, $result->checkpoint);
        self::assertSame(2, $result->counters->accepted);
        self::assertSame(1, $result->counters->rejected);
        self::assertSame(['1', '3'], $sink->saved);
        $rejection = ImportRejection::query()->where(ImportRejection::FIELD_EXTERNAL_ID, '2')->sole();
        self::assertSame(RejectionReason::MALFORMED, $rejection->reason);
        self::assertSame(['rating' => 'INF'], $rejection->context);
        $this->assertDatabaseHas('import_runs', ['status' => 'completed', 'checkpoint' => 3]);
        $this->assertDatabaseCount('import_processed_titles', 3);
    }

    #[Test]
    public function it_imports_another_provider_using_the_configured_context(): void
    {
        $record = new ProviderTitleRecord(ProviderSource::Jikan, '1', ['name' => 'Other provider title']);
        $client = $this->createStub(ProviderClientContract::class);
        $client->method('source')->willReturn(ProviderSource::Jikan);
        $client->method('fetchTitles')->willReturnCallback(static function () use ($record): \Generator {
            yield $record;
        });
        $mapper = $this->createMock(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Jikan);
        $title = (new ShikimoriTitleMapper)->map(ProviderRecords::shikimori($this->row('1')));
        $mapper->expects(self::once())->method('map')->with(self::identicalTo($record))->willReturn(
            new ProviderTitle(...array_replace(get_object_vars($title), ['source' => ProviderSource::Jikan])),
        );
        $this->app->instance(ProviderClientFactoryContract::class, new SingleProviderClientFactory($client));
        $this->app->instance(ProviderTitleMapperContract::class, $mapper);
        config()->set('import.providers.mappers.jikan.api', ProviderTitleMapperContract::class);
        $sink = $this->sink();

        $result = $this->importer()->execute(ProviderSource::Jikan, ProviderDataSource::Api);

        self::assertSame(1, $result->counters->accepted);
        self::assertSame(['1'], $sink->saved);
        $this->assertDatabaseHas('import_runs', ['provider' => 'jikan', 'status' => 'completed', 'checkpoint' => 1]);
        $this->assertDatabaseCount('import_processed_titles', 1);
    }

    #[Test]
    public function it_stops_on_a_dump_record_without_a_stable_identity(): void
    {
        CommittedShikimoriDump::configure(CommittedShikimoriDump::create($this->directory, "{\"russian\":\"Title\"}\n"));
        $this->sink();

        try {
            $this->importer()->execute(ProviderSource::Shikimori, ProviderDataSource::Dump);
            self::fail('A record without identity must stop the source.');
        } catch (RuntimeException $exception) {
            self::assertSame('Invalid Shikimori dump: title must have an id.', $exception->getMessage());
        }

        $this->assertDatabaseHas('import_runs', ['status' => 'failed', 'checkpoint' => 0]);
        $this->assertDatabaseCount('import_rejections', 0);
        $this->assertDatabaseCount('import_processed_titles', 0);
    }

    #[Test]
    public function it_fails_to_resume_when_no_run_exists(): void
    {
        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, resume: true);
            self::fail('Resume without a run must fail.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('no import run to resume', $e->getMessage());
        }

        $this->assertDatabaseCount('import_runs', 0);
    }

    #[Test]
    public function it_supersedes_an_abandoned_running_run_when_a_new_one_starts(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $abandoned = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        $otherProvider = $recorder->start(ProviderSource::Shikimori, ProviderDataSource::Api, null);
        $otherSource = $recorder->start(ProviderSource::Mock, ProviderDataSource::Dump, null);

        $fresh = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);

        $this->assertDatabaseHas('import_runs', [
            'id' => $abandoned->runId,
            'status' => 'superseded',
            'error_message' => 'Superseded by run started later',
        ]);
        self::assertNotNull(DB::table('import_runs')->where('id', $abandoned->runId)->value('finished_at'));
        $this->assertDatabaseHas('import_runs', ['id' => $fresh->runId, 'status' => 'running']);
        $this->assertDatabaseHas('import_runs', ['id' => $otherProvider->runId, 'status' => 'running']);
        $this->assertDatabaseHas('import_runs', ['id' => $otherSource->runId, 'status' => 'running']);
    }

    #[Test]
    public function it_fences_a_superseded_worker_from_failing_the_new_run(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $abandoned = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        $fresh = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);

        try {
            $recorder->fail($abandoned, 'late failure');
            self::fail('A superseded worker must not touch the run.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('no longer owned', $e->getMessage());
        }

        $this->assertDatabaseHas('import_runs', ['id' => $fresh->runId, 'status' => 'running']);
        $this->assertDatabaseHas('import_runs', ['id' => $abandoned->runId, 'status' => 'superseded']);
    }

    #[Test]
    public function it_fences_a_superseded_worker_from_committing_a_batch(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $abandoned = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        $written = false;

        try {
            $recorder->commit(
                $abandoned,
                new ImportBatch(new ImportCounters(fetched: 1, accepted: 1), 1, ['1'], []),
                static function () use (&$written): void {
                    $written = true;
                },
            );
            self::fail('A superseded worker must not commit a batch.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('no longer owned', $e->getMessage());
        }

        self::assertFalse($written);
        $this->assertDatabaseHas('import_runs', ['id' => $abandoned->runId, 'status' => 'superseded', 'checkpoint' => 0, 'fetched' => 0]);
        $this->assertDatabaseCount('import_processed_titles', 0);
    }

    #[Test]
    #[DataProvider('brokenApiCatalogs')]
    public function it_fails_the_run_when_the_api_cannot_serve_the_catalog(int $pageSize, string $message): void
    {
        Sleep::fake();
        config()->set('shikimori.page_size', $pageSize);
        Http::fake(['*' => Http::response(['data' => ['animes' => []]])]);
        $sink = $this->sink();

        try {
            $this->importer()->execute(ProviderSource::Shikimori, ProviderDataSource::Api);
            self::fail('The run must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }

        self::assertSame([], $sink->saved);
        $this->assertDatabaseCount('import_runs', 1);
        $this->assertDatabaseHas('import_runs', ['provider' => 'shikimori', 'status' => 'failed', 'checkpoint' => 0, 'fetched' => 0]);
        self::assertStringContainsString($message, (string) DB::table('import_runs')->value('error_message'));
    }

    public static function brokenApiCatalogs(): array
    {
        return [
            'empty first page' => [50, 'empty first page'],
            'page size above the api limit' => [51, 'must not exceed 50'],
        ];
    }

    #[Test]
    public function it_replays_the_uncommitted_tail_after_finish_fails(): void
    {
        $client = $this->client('1', '2');
        $sink = $this->sink(failFinish: true);
        $this->bindClient($client);

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, 2);
            self::fail('The failing sink must stop the run.');
        } catch (RuntimeException $e) {
            self::assertSame('Finish failed', $e->getMessage());
        }

        $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'accepted' => 0, 'status' => 'failed']);
        $this->assertDatabaseCount('import_processed_titles', 0);
        self::assertSame([], $sink->pending);

        $sink = $this->sink();
        $result = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, 2, true);

        self::assertSame(ImportRunMode::Resumed, $result->mode);
        self::assertSame(['1', '2'], $sink->saved);
        $this->assertDatabaseHas('import_runs', ['checkpoint' => 2, 'accepted' => 2, 'status' => 'completed']);
        $this->assertDatabaseCount('import_processed_titles', 2);
    }

    #[Test]
    public function it_resumes_api_by_identity_when_a_preceding_title_disappears(): void
    {
        config()->set('import.checkpoint_every', 1);
        $this->bindClient($this->client('1', '2', '3'));
        $sink = $this->sink(failFlush: 2);

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, 3);
            self::fail('The second flush must fail.');
        } catch (RuntimeException $e) {
            self::assertSame('Flush failed', $e->getMessage());
        }

        self::assertSame(['1'], $sink->saved);
        $this->assertDatabaseHas('import_runs', ['checkpoint' => 1, 'accepted' => 1, 'status' => 'failed']);
        $this->assertDatabaseCount('import_processed_titles', 1);

        $this->bindClient($this->client('2', '3'));
        $sink = $this->sink();
        $result = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, 3, true);

        self::assertSame(['2', '3'], $sink->saved);
        self::assertSame(3, $result->counters->accepted);
        $this->assertDatabaseCount('import_processed_titles', 3);
    }

    #[Test]
    public function it_discards_buffered_titles_when_the_provider_fails(): void
    {
        $client = $this->client('1', '2');
        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock), 1);
        $this->bindClient($client);
        $sink = $this->sink();

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('Provider failure must propagate.');
        } catch (ProviderUnavailableException) {
            $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'fetched' => 0, 'status' => 'failed']);
            self::assertSame([], $sink->pending);
            self::assertSame([], $sink->saved);
        }
    }

    #[Test]
    public function it_refuses_a_changed_dump_snapshot_on_resume(): void
    {
        $path = CommittedShikimoriDump::create($this->directory, json_encode($this->row('1'))."\n");
        CommittedShikimoriDump::configure($path);
        $this->sink(failFinish: true);

        try {
            $this->importer()->execute(ProviderSource::Shikimori, ProviderDataSource::Dump);
        } catch (RuntimeException $e) {
            self::assertSame('Finish failed', $e->getMessage());
        }

        CommittedShikimoriDump::create($this->directory, json_encode($this->row('2'))."\n");
        $sink = $this->sink();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dump snapshot changed');

        try {
            $this->importer()->execute(ProviderSource::Shikimori, ProviderDataSource::Dump, resume: true);
        } finally {
            self::assertSame([], $sink->saved);
            $this->assertDatabaseCount('import_runs', 1);
        }
    }

    #[Test]
    public function it_does_not_create_a_run_when_another_import_owns_the_lock(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already running');

        try {
            app(ImportRunLockContract::class)->synchronized(ProviderSource::Mock, ProviderDataSource::Api, function (): void {
                $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            });
        } finally {
            $this->assertDatabaseCount('import_runs', 0);
        }
    }

    #[Test]
    public function it_releases_the_lock_after_failure(): void
    {
        $lock = app(ImportRunLockContract::class);

        try {
            $lock->synchronized(ProviderSource::Mock, ProviderDataSource::Api, static fn () => throw new RuntimeException('failure'));
        } catch (RuntimeException) {
        }

        self::assertSame('ok', $lock->synchronized(ProviderSource::Mock, ProviderDataSource::Api, static fn () => 'ok'));
    }

    #[Test]
    public function it_rejects_a_legacy_checkpoint_without_confirmed_identities(): void
    {
        $recorder = app(EloquentImportRunRecorder::class);
        $run = $recorder->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        DB::table('import_runs')->where('id', $run->runId)->update(['checkpoint' => 1, 'fetched' => 1, 'accepted' => 1]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy import checkpoint');
        $recorder->resume(ProviderSource::Mock, ProviderDataSource::Api, null);
    }

    #[Test]
    #[DataProvider('failureLoggers')]
    public function it_preserves_the_original_failure_when_recording_it_also_fails(bool $loggerFails): void
    {
        if ($loggerFails) {
            Log::shouldReceive('error')->once()->andThrow(new RuntimeException('Logger down'));
        }
        $recorder = $this->createStub(ImportRunRecorderContract::class);
        $run = app(EloquentImportRunRecorder::class)->start(ProviderSource::Mock, ProviderDataSource::Api, null);
        $recorder->method('start')->willReturn($run);
        $recorder->method('processedIds')->willReturn([]);
        $recorder->method('fail')->willThrowException(new RuntimeException('Recorder failed'));
        $this->app->instance(ImportRunRecorderContract::class, $recorder);
        $client = $this->client('1');
        $original = new ProviderUnavailableException(ProviderSource::Mock, 'Original API failure');
        $client->failWith($original);
        $this->bindClient($client);
        $this->sink();
        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('Original failure must propagate.');
        } catch (ProviderUnavailableException $caught) {
            self::assertSame($original, $caught);
        }
    }

    public static function failureLoggers(): array
    {
        return ['working logger' => [false], 'broken logger' => [true]];
    }

    #[Test]
    public function it_completes_the_run_when_candidate_diagnostics_fail_after_each_commit(): void
    {
        config()->set('import.checkpoint_every', 2);
        $this->bindClient($this->client('1', '2', '3'));
        $this->app->instance(CandidateSinkContract::class, new LogCandidateSink);
        Log::shouldReceive('info')->times(3)->andThrow(new RuntimeException('Logger down'));
        Log::shouldReceive('warning')->never();

        $result = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        self::assertSame(3, $result->checkpoint);
        self::assertSame(3, $result->counters->accepted);
        $this->assertDatabaseHas('import_runs', ['status' => 'completed', 'checkpoint' => 3]);
        $this->assertDatabaseCount('import_processed_titles', 3);
    }

    #[Test]
    public function it_does_not_flush_or_change_run_state_after_losing_the_lock(): void
    {
        $this->bindClient($this->client('1', '2'));
        $sink = $this->sink();
        $this->app->instance(ImportRunLockContract::class, new class implements ImportRunLockContract
        {
            public function synchronized(ProviderSource $provider, ProviderDataSource $source, Closure $work): mixed
            {
                return $work();
            }

            public function assertOwned(): void
            {
                throw new RuntimeException('Simulated ownership loss');
            }
        });
        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, 2);
            self::fail('A worker without its lock must stop.');
        } catch (RuntimeException $exception) {
            self::assertSame('Simulated ownership loss', $exception->getMessage());
        }
        self::assertSame([], $sink->saved);
        self::assertSame([], $sink->pending);
        $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'status' => 'running']);
        $this->assertDatabaseCount('import_processed_titles', 0);
    }


    #[Test]
    public function it_does_not_persist_rejections_from_a_batch_that_never_committed(): void
    {
        $mapper = new ShikimoriTitleMapper;
        $client = new MockProviderClient([
            ProviderRecords::mock($mapper->map(ProviderRecords::shikimori(['id' => '1', 'status' => 'released']))),
            ProviderRecords::mock($mapper->map(ProviderRecords::shikimori($this->row('2')))),
        ]);
        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock), 1);
        $this->bindClient($client);
        $this->sink();

        try {
            $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);
            self::fail('The provider failure must stop the batch.');
        } catch (ProviderUnavailableException) {
            $this->assertDatabaseCount('import_rejections', 0);
            $this->assertDatabaseCount('import_processed_titles', 0);
            $this->assertDatabaseHas('import_runs', ['checkpoint' => 0, 'rejected' => 0]);
        }

        $this->bindClient($this->client('1', '2'));
        $sink = $this->sink();
        $result = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, resume: true);

        self::assertSame(['1', '2'], $sink->saved);
        self::assertSame(2, $result->counters->accepted);
        self::assertSame(0, $result->counters->rejected);
        $this->assertDatabaseCount('import_rejections', 0);
    }


    #[Test]
    public function it_returns_a_completed_run_on_resume_without_replaying_the_provider(): void
    {
        $this->bindClient($this->client('1'));
        $this->sink();
        $first = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api);

        $client = $this->client('1');
        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock));
        $this->bindClient($client);
        $sink = $this->sink();
        $resumed = $this->importer()->execute(ProviderSource::Mock, ProviderDataSource::Api, resume: true);

        self::assertSame(ImportRunMode::AlreadyCompleted, $resumed->mode);
        self::assertSame($first->runId, $resumed->runId);
        self::assertSame([], $sink->saved);
        $this->assertDatabaseCount('import_runs', 1);
    }


    #[Test]
    public function it_rejects_repeated_identities_in_a_dump_before_delivering_them_twice(): void
    {
        config()->set('import.checkpoint_every', 1);
        $line = json_encode($this->row('1'), JSON_THROW_ON_ERROR)."\n";
        $path = CommittedShikimoriDump::create($this->directory, $line.$line);
        CommittedShikimoriDump::configure($path);
        $sink = $this->sink();

        try {
            $this->importer()->execute(ProviderSource::Shikimori, ProviderDataSource::Dump);
            self::fail('A snapshot with repeated identities must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('repeated provider identity', $exception->getMessage());
        }

        self::assertSame(['1'], $sink->saved);
        $this->assertDatabaseHas('import_runs', ['checkpoint' => 1, 'accepted' => 1, 'status' => 'failed']);
        $this->assertDatabaseCount('import_processed_titles', 1);
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
        $this->directory = sys_get_temp_dir().'/import-reliability-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }


    private function importer(): ImportTitlesFromProvider
    {
        return app(ImportTitlesFromProvider::class);
    }

    private function bindClient(MockProviderClient $client): void
    {
        $this->app->instance(ProviderClientFactoryContract::class, new SingleProviderClientFactory($client));
    }

    private function sink(bool $failFinish = false, int $failFlush = 0): ReliabilitySink
    {
        $sink = new ReliabilitySink($failFinish, $failFlush);
        $this->app->instance(CandidateSinkContract::class, $sink);

        return $sink;
    }

    private function client(string ...$ids): MockProviderClient
    {
        return new MockProviderClient(array_map(fn (string $id) => ProviderRecords::mock((new ShikimoriTitleMapper)->map(ProviderRecords::shikimori($this->row($id)))), $ids));
    }

    private function row(string $id): array
    {
        return ['id' => $id, 'russian' => 'Тайтл '.$id, 'kind' => 'tv', 'status' => 'released', 'description' => 'Описание', 'poster' => ['originalUrl' => 'https://example.test/poster.jpg']];
    }
}

final class ReliabilitySink implements CandidateSinkContract
{
    public array $pending = [];
    public array $saved = [];


    private int $flushes = 0;


    public function __construct(private bool $failFinish, private int $failFlush) {}

    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        $this->pending[] = $candidate->externalId;
    }

    public function flush(): void
    {
        if (++$this->flushes === $this->failFlush) {
            throw new RuntimeException('Flush failed');
        }
        $this->saved = [...$this->saved, ...$this->pending];
        $this->pending = [];
    }

    public function finish(): void
    {
        if ($this->failFinish) {
            throw new RuntimeException('Finish failed');
        }
        $this->flush();
    }

    public function abort(): void
    {
        $this->pending = [];
    }
}
