<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers;

use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use App\Infrastructure\Providers\ProviderClientFactory;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProviderClientFactoryTest extends TestCase
{
    /**
     * @throws BindingResolutionException
     */
    #[Test]
    public function it_makes_the_client_mapped_to_a_source(): void
    {
        $client = $this->factory()->make(ProviderSource::Mock);

        self::assertSame(MockProviderClient::class, $client::class);
    }

    /**
     * @throws BindingResolutionException
     */
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
        $sources = $this->factory(enabled: [ProviderSource::Mock->value])->enabledSources();

        self::assertSame([ProviderSource::Mock], $sources);
    }

    #[Test]
    public function it_rejects_an_unknown_enabled_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory(enabled: ['does-not-exist'])->enabledSources();
    }

    /**
     * @throws BindingResolutionException
     */
    #[Test]
    public function it_forbids_the_mock_client_in_production(): void
    {
        $this->expectException(RuntimeException::class);

        $this->factory(isProduction: true)->make(ProviderSource::Mock);
    }

    /**
     * @param  list<string>  $enabled
     */
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
