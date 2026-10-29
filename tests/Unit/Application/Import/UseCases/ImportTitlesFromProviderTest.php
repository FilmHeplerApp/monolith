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
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

        self::assertTrue($result->resumed);
        self::assertSame(2, $result->checkpoint);
        self::assertSame(2, $result->counters->fetched);
        self::assertSame(2, $result->counters->accepted);
        self::assertCount(1, $sink->items);
        self::assertSame('2', $sink->items[0]['candidate']->externalId);
    }


    private function importer(
        ProviderClientContract    $client,
        InMemoryImportRunRecorder $recorder,
        CollectingCandidateSink   $sink,
    ): ImportTitlesFromProvider {
        return new ImportTitlesFromProvider(
            streams: new ImportTitleStreamFactory(new SingleProviderClientFactory($client), $recorder),
            mapper: new ProviderTitleToCandidateMapper(new ProviderTitleNormalizer),
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
    ): ProviderTitle {
        return new ProviderTitle(
            source: ProviderSource::Mock,
            externalId: $externalId,
            title: LocalizedText::create($titleRu, $titleEn),
            description: LocalizedText::create($description, null),
            genres: [],
            studios: [],
            year: 2020,
            durationMinutes: 24,
            rating: 8.0,
            ratingCount: 100,
            posterUrl: 'https://example.test/poster.jpg',
            bannerUrl: null,
            type: $type,
            status: 'released',
        );
    }
}

final class InMemoryImportRunRecorder implements ImportRunRecorderContract
{
    public array $rejections = [];
    public bool $completed = false;
    public int $checkpoint = 0;


    public function __construct(private readonly ?ImportRunCheckpoint $resumable = null) {}

    public function start(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit,
    ): ImportRunCheckpoint {
        return new ImportRunCheckpoint(1, 0, new ImportCounters, $limit, 'owner');
    }

    public function resume(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit,
    ): ?ImportRunCheckpoint {
        return $this->resumable;
    }

    public function commit(ImportRunCheckpoint $run, ImportBatch $batch, Closure $write, bool $complete = false): ImportRunCheckpoint
    {
        $write();
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
