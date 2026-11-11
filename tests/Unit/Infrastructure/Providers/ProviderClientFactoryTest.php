<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Factories\ProviderClientFactory;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriDumpProviderClient;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriProviderClient;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProviderClientFactoryTest extends TestCase
{
    #[Test]
    public function it_makes_the_client_mapped_to_a_source(): void
    {
        $client = $this->factory(enabled: [ProviderSource::Mock->value])->make(ProviderSource::Mock);

        self::assertSame(MockProviderClient::class, $client::class);
    }


    #[Test]
    public function it_throws_when_no_client_is_registered_for_a_source(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No [dump] client registered for provider [mock].');

        $this->factory(enabled: [ProviderSource::Mock->value])->make(ProviderSource::Mock, ProviderDataSource::Dump);
    }

    #[Test]
    #[DataProvider('dataSources')]
    public function it_refuses_to_make_a_registered_client_of_a_disabled_provider(ProviderDataSource $dataSource): void
    {
        $factory = $this->factoryWithAllClients(enabled: [ProviderSource::Mock->value]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider [shikimori] is disabled.');

        $factory->make(ProviderSource::Shikimori, $dataSource);
    }

    public static function dataSources(): array
    {
        return [
            'api' => [ProviderDataSource::Api],
            'dump' => [ProviderDataSource::Dump],
        ];
    }

    #[Test]
    public function it_refuses_a_disabled_provider_that_has_no_registered_client(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider [shikimori] is disabled.');

        $this->factory(enabled: [ProviderSource::Mock->value])->make(ProviderSource::Shikimori);
    }

    #[Test]
    public function it_returns_only_enabled_clients_when_a_disabled_provider_is_registered(): void
    {
        $factory = $this->factoryWithAllClients(enabled: [ProviderSource::Mock->value]);

        self::assertSame([ProviderSource::Mock], $factory->getEnabledSources());
        self::assertSame([ProviderSource::Mock->value], array_keys($factory->enabled()));
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
    public function it_rejects_an_enabled_provider_with_empty_registered_provider_handlers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No client registered for provider [mock].');

        new ProviderClientFactory(
            container: new Container,
            clients: [
                ProviderSource::Mock->value => [],
            ],
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
    public function it_refuses_to_make_a_disabled_mock_client_in_production(): void
    {
        $factory = $this->factory(isProduction: true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider [mock] is disabled.');

        $factory->make(ProviderSource::Mock);
    }


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

    #[Test]
    public function it_allows_a_disabled_mock_registration_in_production(): void
    {
        $factory = new ProviderClientFactory(
            container: new Container,
            clients: [
                'mock' => MockProviderClient::class,
                'shikimori' => ShikimoriProviderClient::class,
            ],
            enabled: ['shikimori'],
            isProduction: true,
        );

        self::assertSame([ProviderSource::Shikimori], $factory->getEnabledSources());
    }

    #[Test]
    public function it_preserves_both_data_source_registrations_for_the_same_provider(): void
    {
        $api = $this->createStub(ProviderClientContract::class);
        $dump = $this->createStub(ProviderClientContract::class);
        $container = new Container;
        $container->instance(ShikimoriProviderClient::class, $api);
        $container->instance(ShikimoriDumpProviderClient::class, $dump);
        $factory = new ProviderClientFactory($container, ['shikimori' => [
            'api' => ShikimoriProviderClient::class,
            'dump' => ShikimoriDumpProviderClient::class,
        ]], ['shikimori'], false);

        self::assertSame($api, $factory->make(ProviderSource::Shikimori, ProviderDataSource::Api));
        self::assertSame($dump, $factory->make(ProviderSource::Shikimori, ProviderDataSource::Dump));
    }

    #[Test]
    public function it_does_not_use_a_dump_registration_for_an_api_request(): void
    {
        $factory = new ProviderClientFactory(new Container, ['mock' => ['dump' => MockProviderClient::class]], ['mock'], false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No [api] client registered for provider [mock].');
        $factory->make(ProviderSource::Mock, ProviderDataSource::Api);
    }

    #[Test]
    public function it_rejects_an_enabled_provider_missing_from_a_nonempty_registry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No client registered for provider [shikimori].');

        $this->factory(enabled: ['mock', 'shikimori']);
    }

    #[Test]
    public function it_rejects_a_forbidden_secondary_data_source_for_an_enabled_provider(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MockProviderClient is not allowed in production.');

        new ProviderClientFactory(
            container: new Container,
            clients: ['shikimori' => [
                'api' => ShikimoriProviderClient::class,
                'dump' => MockProviderClient::class,
            ]],
            enabled: ['shikimori'],
            isProduction: true,
        );
    }

    #[Test]
    public function it_deduplicates_enabled_providers_and_preserves_their_order(): void
    {
        $factory = new ProviderClientFactory(
            container: new Container,
            clients: [
                'mock' => MockProviderClient::class,
                'shikimori' => ShikimoriProviderClient::class,
            ],
            enabled: ['shikimori', 'mock', 'shikimori', 'mock'],
            isProduction: false,
        );

        self::assertSame([ProviderSource::Shikimori, ProviderSource::Mock], $factory->getEnabledSources());
    }

    #[Test]
    public function it_allows_an_empty_configuration(): void
    {
        $factory = new ProviderClientFactory(new Container, [], [], true);

        self::assertSame([], $factory->getEnabledSources());
        self::assertSame([], $factory->enabled());
    }

    #[Test]
    public function it_allows_empty_registrations_for_disabled_providers(): void
    {
        $factory = new ProviderClientFactory(new Container, ['mock' => []], [], true);

        self::assertSame([], $factory->getEnabledSources());
    }

    #[Test]
    #[DataProvider('invalidClientClasses')]
    public function it_rejects_invalid_client_classes_even_for_disabled_providers(mixed $class): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid client registered for provider [mock].');

        new ProviderClientFactory(new Container, ['mock' => ['api' => $class]], [], false);
    }

    public static function invalidClientClasses(): array
    {
        return [
            'null' => [null],
            'boolean' => [false],
            'integer' => [42],
            'array' => [[]],
            'unrelated class' => [\stdClass::class],
            'missing class' => ['MissingProviderClient'],
        ];
    }

    #[Test]
    #[DataProvider('invalidDataSources')]
    public function it_rejects_unknown_data_sources(int|string $dataSource): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown import data source [$dataSource].");

        new ProviderClientFactory(new Container, ['mock' => [$dataSource => MockProviderClient::class]], [], false);
    }

    public static function invalidDataSources(): array
    {
        return [
            'unknown name' => ['unknown'],
            'numeric key' => [0],
        ];
    }

    #[Test]
    public function it_rejects_an_unknown_registered_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown import provider [unknown].');

        new ProviderClientFactory(new Container, ['unknown' => MockProviderClient::class], [], false);
    }

    #[Test]
    public function it_rejects_a_non_string_enabled_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Import provider names must be strings.');

        $this->factory(enabled: [null]);
    }

    #[Test]
    public function it_rejects_a_non_string_registered_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Import provider names must be strings.');

        new ProviderClientFactory(new Container, [MockProviderClient::class], [], false);
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

    private function factoryWithAllClients(array $enabled): ProviderClientFactory
    {
        return new ProviderClientFactory(
            container: new Container,
            clients: [
                ProviderSource::Mock->value => MockProviderClient::class,
                ProviderSource::Shikimori->value => [
                    ProviderDataSource::Api->value => ShikimoriProviderClient::class,
                    ProviderDataSource::Dump->value => ShikimoriDumpProviderClient::class,
                ],
            ],
            enabled: $enabled,
            isProduction: false,
        );
    }
}
