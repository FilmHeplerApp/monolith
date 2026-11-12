<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\UseCases;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\DTOs\ImportBatch;
use App\Application\Import\DTOs\ImportCounters;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ImportRunMode;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Services\ImportTitleStreamFactory;
use App\Application\Import\Services\ProviderTitleNormalizer;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Rules\CompletenessRule;
use App\Domain\Import\Rules\HasAnyTitleRule;
use App\Domain\Import\Services\TitleCandidateEvaluator;
use App\Infrastructure\Providers\Factories\ProviderImportContextFactory;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use App\Infrastructure\Providers\Mock\MockTitleMapper;
use Closure;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ProviderRecords;
use Tests\Support\SingleProviderClientFactory;

final class ImportTitlesFromProviderTest extends TestCase
{
    #[Test]
    public function it_routes_candidates_and_records_the_run(): void
    {
        $client = new MockProviderClient([
            $this->title('1'),
            $this->title('2', description: null),
            $this->title('3', titleRu: null, titleEn: null),
            $this->title('4', type: 'unsupported'),
        ]);
        $recorder = new InMemoryImportRunRecorder;
        $sink = new CollectingCandidateSink;

        $result = $this->importer($client, $recorder, $sink)->execute(
            ProviderSource::Mock,
            ProviderDataSource::Api,
            limit: 4,
        );

        self::assertSame(4, $result->counters->fetched);
        self::assertSame(1, $result->counters->accepted);
        self::assertSame(1, $result->counters->flagged);
        self::assertSame(2, $result->counters->rejected);
        self::assertCount(2, $sink->items);
        self::assertSame(
            [RejectionReason::NO_TITLE, RejectionReason::UNSUPPORTED_TYPE],
            array_column($recorder->rejections, 'reason'),
        );
        self::assertTrue($recorder->completed);
        self::assertSame(4, $recorder->checkpoint);
    }


    #[Test]
    public function it_resumes_from_the_recorded_checkpoint(): void
    {
        $client = new MockProviderClient([$this->title('1'), $this->title('2')]);
        $recorder = new InMemoryImportRunRecorder(
            new ImportRunCheckpoint(10, 1, new ImportCounters(fetched: 1, accepted: 1), 2, 'owner'),
        );
        $sink = new CollectingCandidateSink;

        $result = $this->importer($client, $recorder, $sink)->execute(
            ProviderSource::Mock,
            ProviderDataSource::Api,
            limit: 2,
            resume: true,
        );

        self::assertSame(ImportRunMode::Resumed, $result->mode);
        self::assertSame(2, $result->checkpoint);
        self::assertSame(2, $result->counters->fetched);
        self::assertSame(2, $result->counters->accepted);
        self::assertCount(1, $sink->items);
        self::assertSame('2', $sink->items[0]['candidate']->externalId);
    }


    #[Test]
    public function it_keeps_each_committed_batch_snapshot_and_the_resumed_totals_unchanged(): void
    {
        $client = new MockProviderClient([
            $this->title('1'),
            $this->title('2'),
            $this->title('3', description: null),
            $this->title('4', titleRu: null, titleEn: null),
        ]);
        $resumed = new ImportCounters(fetched: 1, accepted: 1);
        $recorder = new InMemoryImportRunRecorder(new ImportRunCheckpoint(10, 1, $resumed, null, 'owner'));

        $result = $this->importer($client, $recorder, new CollectingCandidateSink)->execute(
            ProviderSource::Mock,
            ProviderDataSource::Api,
            resume: true,
        );

        self::assertEquals([
            new ImportCounters(fetched: 2, accepted: 2),
            new ImportCounters(fetched: 3, accepted: 2, flagged: 1),
            new ImportCounters(fetched: 4, accepted: 2, flagged: 1, rejected: 1),
            new ImportCounters(fetched: 4, accepted: 2, flagged: 1, rejected: 1),
        ], array_map(static fn (ImportBatch $batch): ImportCounters => $batch->counters, $recorder->batches));
        self::assertSame([], $recorder->batches[3]->processedIds, 'The completing batch carries no new identities.');
        self::assertEquals(new ImportCounters(fetched: 1, accepted: 1), $resumed);
        self::assertSame($recorder->batches[3]->counters, $result->counters);
    }

    #[Test]
    public function it_starts_a_new_run_without_resume(): void
    {
        $result = $this->importer(
            new MockProviderClient([$this->title('1')]),
            new InMemoryImportRunRecorder,
            new CollectingCandidateSink,
        )->execute(ProviderSource::Mock, ProviderDataSource::Api);

        self::assertSame(ImportRunMode::New, $result->mode);
    }

    #[Test]
    public function it_fails_when_there_is_nothing_to_resume(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('There is no import run to resume');

        $this->importer(
            new MockProviderClient([$this->title('1')]),
            new InMemoryImportRunRecorder,
            new CollectingCandidateSink,
        )->execute(ProviderSource::Mock, ProviderDataSource::Api, resume: true);
    }

    #[Test]
    public function it_reports_an_already_completed_run_without_reading_the_provider(): void
    {
        $recorder = new InMemoryImportRunRecorder(
            new ImportRunCheckpoint(10, 1, new ImportCounters(fetched: 1, accepted: 1), null, 'owner', true),
        );
        $sink = new CollectingCandidateSink;

        $result = $this->importer(new MockProviderClient([$this->title('2')]), $recorder, $sink)
            ->execute(ProviderSource::Mock, ProviderDataSource::Api, resume: true);

        self::assertSame(ImportRunMode::AlreadyCompleted, $result->mode);
        self::assertSame(10, $result->runId);
        self::assertSame([], $sink->items);
    }

    private function importer(
        ProviderClientContract    $client,
        InMemoryImportRunRecorder $recorder,
        CollectingCandidateSink   $sink,
    ): ImportTitlesFromProvider {
        return new ImportTitlesFromProvider(
            contexts: new ProviderImportContextFactory(new Container, new SingleProviderClientFactory($client), ['mock' => ['api' => MockTitleMapper::class]]),
            streams: new ImportTitleStreamFactory($recorder),
            candidateMapper: new ProviderTitleToCandidateMapper(new ProviderTitleNormalizer),
            filter: new TitleCandidateEvaluator([new HasAnyTitleRule, new CompletenessRule]),
            recorder: $recorder,
            sink: $sink,
            lock: new class implements ImportRunLockContract
            {
                public function synchronized(ProviderSource $provider, ProviderDataSource $source, Closure $work): mixed
                {
                    return $work();
                }

                public function assertOwned(): void {}
            },
            checkpointEvery: 1,
        );
    }

    private function title(
        string  $externalId,
        ?string $titleRu = 'Название',
        ?string $titleEn = 'Title',
        ?string $description = 'Описание',
        string  $type = 'anime',
    ): ProviderTitleRecord {
        return ProviderRecords::mock(new ProviderTitle(
            source: ProviderSource::Mock,
            externalId: $externalId,
            title: LocalizedText::create($titleRu, $titleEn),
            description: LocalizedText::create($description, null),
            year: 2020,
            durationMinutes: 24,
            rating: 8.0,
            posterUrl: 'https://example.test/poster.jpg',
            bannerUrl: null,
            type: $type,
            status: 'released',
            ratingCount: 100,
        ));
    }
}

final class InMemoryImportRunRecorder implements ImportRunRecorderContract
{
    public array $rejections = [];
    public array $batches = [];
    public bool $completed = false;
    public int $checkpoint = 0;


    public function __construct(private readonly ?ImportRunCheckpoint $resumable = null) {}

    public function start(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit,
        Closure            $authorize,
    ): ImportRunCheckpoint {
        $authorize();

        return new ImportRunCheckpoint(1, 0, new ImportCounters, $limit, 'owner');
    }

    public function resume(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit,
        Closure            $authorize,
    ): ?ImportRunCheckpoint {
        $authorize();

        return $this->resumable;
    }

    public function commit(ImportRunCheckpoint $run, ImportBatch $batch, Closure $write, bool $complete = false): ImportRunCheckpoint
    {
        $write();
        $this->batches[] = $batch;
        foreach ($batch->rejections as $rejection) {
            $this->rejections[] = ['external_id' => $rejection->externalId, 'reason' => $rejection->reason, 'context' => $rejection->context];
        }
        $this->checkpoint = $batch->checkpoint;
        $this->completed = $complete;

        return new ImportRunCheckpoint($run->runId, $batch->checkpoint, $batch->counters, $run->limit, $run->ownerToken);
    }

    public function processedIds(int $runId): array
    {
        return $this->resumable === null ? [] : ['1'];
    }

    public function bindSnapshot(ImportRunCheckpoint $run, string $snapshotId): void {}

    public function fail(ImportRunCheckpoint $run, string $message): void
    {
        throw new RuntimeException("Import unexpectedly failed: {$message}");
    }

}

final class CollectingCandidateSink implements CandidateSinkContract
{
    public array $items = [];


    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        $this->items[] = compact('candidate', 'decision');
    }

    public function flush(): void {}

    public function finish(): void {}

    public function abort(): void {}
}
