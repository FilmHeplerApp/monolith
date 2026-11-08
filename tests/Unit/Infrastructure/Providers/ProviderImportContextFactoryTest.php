<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Contracts\ProviderTitleMapperContract;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Factories\ProviderImportContextFactory;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderImportContextFactoryTest extends TestCase
{
    #[Test]
    public function it_selects_a_client_and_mapper_for_another_provider_and_source(): void
    {
        $client = $this->createStub(ProviderClientContract::class);
        $client->method('source')->willReturn(ProviderSource::Jikan);
        $clients = $this->createMock(ProviderClientFactoryContract::class);
        $clients->expects(self::once())->method('make')->with(ProviderSource::Jikan, ProviderDataSource::Dump)->willReturn($client);
        $mapper = $this->createStub(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Jikan);
        $container = new Container;
        $container->instance(ProviderTitleMapperContract::class, $mapper);
        $factory = new ProviderImportContextFactory($container, $clients, [
            'jikan' => ['dump' => ProviderTitleMapperContract::class],
        ]);

        $context = $factory->create(ProviderSource::Jikan, ProviderDataSource::Dump);

        self::assertSame($client, $context->client);
        self::assertSame($mapper, $context->mapper);
    }

    #[Test]
    public function it_rejects_a_missing_mapper_before_creating_a_client(): void
    {
        $clients = $this->createMock(ProviderClientFactoryContract::class);
        $clients->expects(self::never())->method('make');
        $factory = new ProviderImportContextFactory(new Container, $clients, []);

        $this->expectException(InvalidArgumentException::class);
        $factory->create(ProviderSource::Shikimori, ProviderDataSource::Api);
    }

    #[Test]
    public function it_rejects_a_mapper_for_a_different_provider_before_creating_a_client(): void
    {
        $clients = $this->createMock(ProviderClientFactoryContract::class);
        $clients->expects(self::never())->method('make');
        $mapper = $this->createStub(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Mock);
        $container = new Container;
        $container->instance(ProviderTitleMapperContract::class, $mapper);
        $factory = new ProviderImportContextFactory($container, $clients, ['shikimori' => ['api' => ProviderTitleMapperContract::class]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Import mapper does not match the requested provider.');
        $factory->create(ProviderSource::Shikimori, ProviderDataSource::Api);
    }

    #[Test]
    public function it_rejects_a_client_for_a_different_provider(): void
    {
        $client = $this->createStub(ProviderClientContract::class);
        $client->method('source')->willReturn(ProviderSource::Mock);
        $clients = $this->createStub(ProviderClientFactoryContract::class);
        $clients->method('make')->willReturn($client);
        $mapper = $this->createStub(ProviderTitleMapperContract::class);
        $mapper->method('source')->willReturn(ProviderSource::Shikimori);
        $container = new Container;
        $container->instance(ProviderTitleMapperContract::class, $mapper);
        $factory = new ProviderImportContextFactory($container, $clients, ['shikimori' => ['api' => ProviderTitleMapperContract::class]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Import client does not match the requested provider.');
        $factory->create(ProviderSource::Shikimori, ProviderDataSource::Api);
    }

    #[Test]
    public function it_rejects_raw_records_without_an_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProviderTitleRecord(ProviderSource::Shikimori, ' ', ['russian' => 'Title']);
    }

    #[Test]
    public function it_selects_distinct_mappers_for_api_and_dump_of_the_same_provider(): void
    {
        $client = $this->createStub(ProviderClientContract::class);
        $client->method('source')->willReturn(ProviderSource::Shikimori);
        $clients = $this->createStub(ProviderClientFactoryContract::class);
        $clients->method('make')->willReturn($client);
        $apiMapper = new ShikimoriTitleMapper;
        $dumpMapper = $this->createStub(ProviderTitleMapperContract::class);
        $dumpMapper->method('source')->willReturn(ProviderSource::Shikimori);
        $container = new Container;
        $container->instance(ShikimoriTitleMapper::class, $apiMapper);
        $container->instance(ProviderTitleMapperContract::class, $dumpMapper);
        $factory = new ProviderImportContextFactory($container, $clients, ['shikimori' => [
            'api' => ShikimoriTitleMapper::class,
            'dump' => ProviderTitleMapperContract::class,
        ]]);

        self::assertSame($apiMapper, $factory->create(ProviderSource::Shikimori, ProviderDataSource::Api)->mapper);
        self::assertSame($dumpMapper, $factory->create(ProviderSource::Shikimori, ProviderDataSource::Dump)->mapper);
    }

    #[Test]
    public function it_does_not_fall_back_to_an_api_mapper_when_dump_mapping_is_missing(): void
    {
        $clients = $this->createMock(ProviderClientFactoryContract::class);
        $clients->expects(self::never())->method('make');
        $factory = new ProviderImportContextFactory(new Container, $clients, ['shikimori' => ['api' => ShikimoriTitleMapper::class]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing [dump] mapper for provider [shikimori].');
        $factory->create(ProviderSource::Shikimori, ProviderDataSource::Dump);
    }

    #[Test]
    #[DataProvider('invalidMappers')]
    public function it_rejects_invalid_mapper_registrations_before_creating_a_client(mixed $registration): void
    {
        $clients = $this->createMock(ProviderClientFactoryContract::class);
        $clients->expects(self::never())->method('make');
        $factory = new ProviderImportContextFactory(new Container, $clients, ['shikimori' => ['api' => $registration]]);

        $this->expectException(InvalidArgumentException::class);
        $factory->create(ProviderSource::Shikimori, ProviderDataSource::Api);
    }

    public static function invalidMappers(): array
    {
        return [
            'null' => [null],
            'non-string' => [42],
            'array' => [[]],
            'missing class' => ['MissingTitleMapper'],
            'unrelated class' => [\stdClass::class],
        ];
    }
}
