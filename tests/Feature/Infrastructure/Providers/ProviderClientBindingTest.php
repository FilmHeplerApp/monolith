<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Providers;

use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Factories\ProviderClientFactory;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use PHPUnit\Framework\Attributes\Test;
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
    public function it_enables_the_mock_provider_by_default(): void
    {
        $enabled = app(ProviderClientFactoryContract::class)->enabled();

        self::assertArrayHasKey(ProviderSource::Mock->value, $enabled);
        self::assertInstanceOf(MockProviderClient::class, $enabled[ProviderSource::Mock->value]);
    }
}
