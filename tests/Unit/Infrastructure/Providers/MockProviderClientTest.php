<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers;

use App\Application\Import\DTO\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderRateLimitedException;
use App\Application\Import\Exceptions\ProviderUnavailableException;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use PHPUnit\Framework\TestCase;

final class MockProviderClientTest extends TestCase
{
    public function test_it_reports_its_source(): void
    {
        self::assertSame(ProviderSource::Mock, new MockProviderClient()->source());
    }

    public function test_it_yields_provider_titles(): void
    {
        $titles = iterator_to_array(new MockProviderClient()->fetchTitles());

        self::assertNotEmpty($titles);
        self::assertContainsOnlyInstancesOf(ProviderTitle::class, $titles);
    }

    public function test_it_respects_the_limit(): void
    {
        $titles = iterator_to_array(new MockProviderClient()->fetchTitles(limit: 2));

        self::assertCount(2, $titles);
    }

    public function test_it_finds_a_title_by_external_id(): void
    {
        $client = new MockProviderClient;

        $found = $client->fetchTitleByExternalId('5114');

        self::assertNotNull($found);
        self::assertSame('5114', $found->externalId);
    }

    public function test_it_returns_null_for_unknown_external_id(): void
    {
        self::assertNull(new MockProviderClient()->fetchTitleByExternalId('nope'));
    }

    public function test_it_can_simulate_a_rate_limit(): void
    {
        $client = new MockProviderClient;
        $client->failWith(
            new ProviderRateLimitedException(ProviderSource::Mock, retryAfterSeconds: 30),
            afterItems: 1,
        );

        $this->expectException(ProviderRateLimitedException::class);

        iterator_to_array($client->fetchTitles());
    }

    public function test_it_can_simulate_provider_unavailability(): void
    {
        $client = new MockProviderClient;
        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock));

        try {
            iterator_to_array($client->fetchTitles());
            self::fail('Expected ProviderUnavailableException.');
        } catch (ProviderUnavailableException $e) {
            self::assertSame(ProviderSource::Mock, $e->source);
        }
    }
}
