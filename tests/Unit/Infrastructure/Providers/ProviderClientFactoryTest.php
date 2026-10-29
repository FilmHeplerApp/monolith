<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers;

use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Factories\ProviderClientFactory;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use RuntimeException;

final class ProviderClientFactoryTest extends TestCase
{
    #[Test]
    public function it_makes_the_client_mapped_to_a_source(): void
    {
        $client = $this->factory()->make(ProviderSource::Mock);

        self::assertSame(MockProviderClient::class, $client::class);
    }


    #[Test]
    public function it_throws_when_no_client_is_registered_for_a_source(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->make(ProviderSource::Shikimori);
    }


    #[Test]
    public function it_returns_enabled_clients_keyed_by_source(): void
    {
        $clients = $this->factory(enabled: [ProviderSource::Mock->value])->enabled();

        self::assertArrayHasKey(ProviderSource::Mock->value, $clients);
        self::assertInstanceOf(MockProviderClient::class, $clients[ProviderSource::Mock->value]);
    }

    #[Test]
    public function it_exposes_enabled_sources_as_enums(): void
    {
        $sources = $this->factory(enabled: [ProviderSource::Mock->value])->getEnabledSources();

        self::assertSame([ProviderSource::Mock], $sources);
    }

    #[Test]
    public function it_rejects_an_unknown_enabled_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown import provider [does-not-exist].');

        $this->factory(enabled: ['does-not-exist']);
    }

    #[Test]
    public function it_rejects_an_enabled_provider_with_no_registered_client(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No client registered for provider [mock].');

        new ProviderClientFactory(
            container: new Container,
            clients: [],
            enabled: [ProviderSource::Mock->value],
            isProduction: false,
        );
    }

    #[Test]
    public function it_rejects_an_enabled_mock_client_in_production_at_construction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MockProviderClient is not allowed in production.');

        $this->factory(enabled: [ProviderSource::Mock->value], isProduction: true);
    }


    #[Test]
    public function it_forbids_making_the_mock_client_in_production(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MockProviderClient is not allowed in production.');

        $this->factory(isProduction: true)->make(ProviderSource::Mock);
    }


    /**
     * @throws ReflectionException
     */
    #[Test]
    public function it_normalizes_dump_only_providers_without_constructing_their_clients(): void
    {
        $container = new Container;
        $created = 0;
        $container->bind(MockProviderClient::class, function () use (&$created): MockProviderClient {
            $created++;

            return new MockProviderClient([]);
        });
        $factory = new ProviderClientFactory(
            $container,
            ['mock' => ['dump' => MockProviderClient::class]],
            ['mock'],
            false,
        );

        self::assertSame(0, $created);
        $first = $factory->make(ProviderSource::Mock, ProviderDataSource::Dump);
        $second = $factory->make(ProviderSource::Mock, ProviderDataSource::Dump);
        self::assertSame(2, $created);
        self::assertNotSame($first, $second);
    }


    private function factory(array $enabled = [], bool $isProduction = false): ProviderClientFactory
    {
        return new ProviderClientFactory(
            container: new Container,
            clients: [ProviderSource::Mock->value => MockProviderClient::class],
            enabled: $enabled,
            isProduction: $isProduction,
        );
    }
}
