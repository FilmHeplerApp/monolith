<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Providers;

use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Factories\ProviderClientFactory;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ProviderClientBindingTest extends TestCase
{
    #[Test]
    public function it_binds_the_provider_client_factory_from_config(): void
    {
        $factory = app(ProviderClientFactoryContract::class);

        self::assertInstanceOf(ProviderClientFactory::class, $factory);
    }

    #[Test]
    public function it_enables_the_mock_provider_in_the_test_environment(): void
    {
        $enabled = app(ProviderClientFactoryContract::class)->enabled();

        self::assertArrayHasKey(ProviderSource::Mock->value, $enabled);
        self::assertInstanceOf(MockProviderClient::class, $enabled[ProviderSource::Mock->value]);
    }

    #[Test]
    public function it_starts_with_only_mock_enabled_and_refuses_the_configured_disabled_provider(): void
    {
        $factory = $this->factoryWithEnabled([ProviderSource::Mock->value]);

        self::assertSame([ProviderSource::Mock], $factory->getEnabledSources());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider [shikimori] is disabled.');

        $factory->make(ProviderSource::Shikimori, ProviderDataSource::Dump);
    }

    #[Test]
    public function it_starts_in_production_with_only_shikimori_enabled(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $factory = $this->factoryWithEnabled([ProviderSource::Shikimori->value]);

        self::assertSame([ProviderSource::Shikimori], $factory->getEnabledSources());
    }

    #[Test]
    public function it_fails_in_production_when_the_mock_provider_is_enabled(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MockProviderClient is not allowed in production.');

        $this->factoryWithEnabled([ProviderSource::Mock->value]);
    }


    private function factoryWithEnabled(array $enabled): ProviderClientFactoryContract
    {
        config()->set('import.providers.enabled', $enabled);
        $this->app->forgetInstance(ProviderClientFactoryContract::class);

        return app(ProviderClientFactoryContract::class);
    }
}
