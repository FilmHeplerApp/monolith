<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\UseCases;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\DTOs\ImportCounters;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Services\ProviderTitleNormalizer;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Rules\CompletenessRule;
use App\Domain\Import\Rules\HasAnyTitleRule;
use App\Domain\Import\Services\ImportFilterEngine;
use App\Domain\Import\ValueObjects\FilterDecision;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ImportTitlesFromProviderTest extends TestCase
{
    /**
     * @throws Throwable
     */
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

    /**
     * @throws Throwable
     */
    #[Test]
    public function it_resumes_from_the_recorded_checkpoint(): void
    {
        $client = new MockProviderClient([$this->title('1'), $this->title('2')]);
        $recorder = new InMemoryImportRunRecorder(
            new ImportRunCheckpoint(10, 1, new ImportCounters(fetched: 1, accepted: 1), 2),
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
            clients: new SingleProviderClientFactory($client),
            mapper: new ProviderTitleToCandidateMapper(new ProviderTitleNormalizer),
            filter: new ImportFilterEngine([new HasAnyTitleRule, new CompletenessRule]),
            recorder: $recorder,
            sink: $sink,
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

final readonly class SingleProviderClientFactory implements ProviderClientFactoryContract
{
    public function __construct(private ProviderClientContract $client) {}

    public function make(
        ProviderSource     $source,
        ProviderDataSource $dataSource = ProviderDataSource::Api,
    ): ProviderClientContract {
        return $this->client;
    }

    public function enabled(): array
    {
        return [$this->client->source()->value => $this->client];
    }

    public function getEnabledSources(): array
    {
        return [$this->client->source()];
    }
}

final class InMemoryImportRunRecorder implements ImportRunRecorderContract
{
    /** @var list<array{external_id: string, reason: RejectionReason, context: array<string, mixed>}> */
    public array $rejections = [];

    public bool $completed = false;

    public int $checkpoint = 0;

    public function __construct(private readonly ?ImportRunCheckpoint $resumable = null) {}

    public function start(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit,
    ): ImportRunCheckpoint {
        return new ImportRunCheckpoint(1, 0, new ImportCounters, $limit);
    }

    public function resume(
        ProviderSource     $provider,
        ProviderDataSource $dataSource,
        ?int               $limit,
    ): ?ImportRunCheckpoint {
        return $this->resumable;
    }

    public function progress(int $runId, ImportCounters $counters, int $checkpoint): void
    {
        $this->checkpoint = $checkpoint;
    }

    public function rejection(
        int             $runId,
        string          $externalId,
        RejectionReason $reason,
        array           $context = [],
    ): void {
        $this->rejections[] = [
            'external_id' => $externalId,
            'reason' => $reason,
            'context' => $context,
        ];
    }

    public function complete(int $runId, ImportCounters $counters, int $checkpoint): void
    {
        $this->completed = true;
        $this->checkpoint = $checkpoint;
    }

    public function fail(int $runId, ImportCounters $counters, int $checkpoint, string $message): void
    {
        throw new RuntimeException("Import unexpectedly failed: {$message}");
    }
}

final class CollectingCandidateSink implements CandidateSinkContract
{
    /** @var list<array{candidate: TitleCandidate, decision: FilterDecision}> */
    public array $items = [];

    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        $this->items[] = compact('candidate', 'decision');
    }

    public function finish(): void {}
}
