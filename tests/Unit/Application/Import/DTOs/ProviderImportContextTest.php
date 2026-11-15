<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\DTOs;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderTitleMapperContract;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Services\ProviderImportContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProviderImportContextTest extends TestCase
{
    #[Test]
    public function it_rejects_a_client_and_mapper_of_different_providers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Import client and mapper must belong to the same provider.');

        new ProviderImportContext($this->client(ProviderSource::Shikimori), $this->mapper(ProviderSource::Mock));
    }

    #[Test]
    public function it_interprets_a_record_with_its_mapper(): void
    {
        $record = new ProviderTitleRecord(ProviderSource::Shikimori, '7', ['name' => 'Raw']);
        $title = $this->title(ProviderSource::Shikimori, '7');
        $mapper = $this->createMock(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Shikimori);
        $mapper->expects(self::once())->method('map')->with(self::identicalTo($record))->willReturn($title);

        $context = new ProviderImportContext($this->client(ProviderSource::Shikimori), $mapper);

        self::assertSame(ProviderSource::Shikimori, $context->source());
        self::assertSame($title, $context->interpret($record));
    }

    #[Test]
    public function it_rejects_a_foreign_record_before_calling_the_mapper(): void
    {
        $mapper = $this->createMock(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Shikimori);
        $mapper->expects(self::never())->method('map');
        $context = new ProviderImportContext($this->client(ProviderSource::Shikimori), $mapper);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Import record does not match the provider of its context.');

        $context->interpret(new ProviderTitleRecord(ProviderSource::Mock, '7', []));
    }

    #[Test]
    #[DataProvider('changedIdentities')]
    public function it_rejects_a_mapped_title_with_another_identity(ProviderSource $source, string $externalId): void
    {
        $mapper = $this->mapper(ProviderSource::Shikimori);
        $mapper->method('map')->willReturn($this->title($source, $externalId));
        $context = new ProviderImportContext($this->client(ProviderSource::Shikimori), $mapper);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mapped title identity does not match its raw record.');

        $context->interpret(new ProviderTitleRecord(ProviderSource::Shikimori, '7', []));
    }

    public static function changedIdentities(): array
    {
        return [
            'external id' => [ProviderSource::Shikimori, '8'],
            'provider' => [ProviderSource::Mock, '7'],
        ];
    }


    private function client(ProviderSource $source): ProviderClientContract
    {
        $client = $this->createStub(ProviderClientContract::class);
        $client->method('source')->willReturn($source);

        return $client;
    }

    private function mapper(ProviderSource $source): ProviderTitleMapperContract&Stub
    {
        $mapper = $this->createStub(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn($source);

        return $mapper;
    }

    private function title(ProviderSource $source, string $externalId): ProviderTitle
    {
        return new ProviderTitle($source, $externalId, null, null, null, null, null, null, null, 'anime', 'released');
    }
}
