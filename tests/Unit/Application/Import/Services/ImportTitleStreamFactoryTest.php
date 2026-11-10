<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\Services;

use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderSnapshotContract;
use App\Application\Import\Contracts\ProviderTitleMapperContract;
use App\Application\Import\DTOs\ImportCounters;
use App\Application\Import\DTOs\ImportRunCheckpoint;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Services\ImportTitleStreamFactory;
use App\Application\Import\Services\ProviderImportContext;
use App\Application\Import\Streams\LiveProviderTitleStream;
use App\Application\Import\Streams\SnapshotProviderTitleStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImportTitleStreamFactoryTest extends TestCase
{
    #[Test]
    public function it_reads_a_live_client_from_the_context_as_a_live_stream(): void
    {
        $client = $this->createStub(ProviderClientContract::class);
        $client->method('source')->willReturn(ProviderSource::Shikimori);

        self::assertInstanceOf(LiveProviderTitleStream::class, $this->factory()->create($this->context($client), $this->checkpoint()));
    }

    #[Test]
    public function it_reads_a_snapshot_client_from_the_context_as_a_snapshot_stream(): void
    {
        $client = $this->createStubForIntersectionOfInterfaces([ProviderClientContract::class, ProviderSnapshotContract::class]);
        $client->method('source')->willReturn(ProviderSource::Shikimori);

        self::assertInstanceOf(SnapshotProviderTitleStream::class, $this->factory()->create($this->context($client), $this->checkpoint()));
    }


    private function factory(): ImportTitleStreamFactory
    {
        return new ImportTitleStreamFactory($this->createStub(ImportRunRecorderContract::class));
    }

    private function context(ProviderClientContract $client): ProviderImportContext
    {
        $mapper = $this->createStub(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Shikimori);

        return new ProviderImportContext($client, $mapper);
    }

    private function checkpoint(): ImportRunCheckpoint
    {
        return new ImportRunCheckpoint(1, 0, new ImportCounters, null, 'owner');
    }
}
