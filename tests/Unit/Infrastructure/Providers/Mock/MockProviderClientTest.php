<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers\Mock;

use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderRateLimitedException;
use App\Application\Import\Exceptions\ProviderUnavailableException;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use InvalidArgumentException;
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

    public function test_it_raises_a_rate_limit_only_while_iterating(): void
    {
        $client = new MockProviderClient;
        $client->failWith(
            new ProviderRateLimitedException(ProviderSource::Mock, retryAfterSeconds: 30),
            afterItems: 1,
        );

        $stream = $client->fetchTitles();

        $seen = 0;

        try {
            foreach ($stream as $title) {
                self::assertInstanceOf(ProviderTitle::class, $title);
                $seen++;
            }

            self::fail('Expected ProviderRateLimitedException during iteration.');
        } catch (ProviderRateLimitedException $e) {
            self::assertSame(1, $seen);
            self::assertSame(ProviderSource::Mock, $e->source);
            self::assertSame(30, $e->retryAfterSeconds);
        }
    }

    public function test_it_raises_unavailability_only_while_iterating(): void
    {
        $client = new MockProviderClient;
        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock));

        $stream = $client->fetchTitles();

        $seen = 0;

        try {
            foreach ($stream as $title) {
                $seen++;
            }

            self::fail('Expected ProviderUnavailableException during iteration.');
        } catch (ProviderUnavailableException $e) {
            self::assertSame(0, $seen);
            self::assertSame(ProviderSource::Mock, $e->source);
        }
    }

    public function test_it_rejects_a_failure_beyond_available_titles(): void
    {
        $client = new MockProviderClient;

        $this->expectException(InvalidArgumentException::class);

        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock), afterItems: 999);
    }

    public function test_it_rejects_a_failure_unreachable_within_the_limit(): void
    {
        $client = new MockProviderClient;
        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock), afterItems: 2);

        $this->expectException(\InvalidArgumentException::class);

        iterator_to_array($client->fetchTitles(limit: 2));
    }

    public function test_default_fixtures_include_incomplete_titles(): void
    {
        $titles = iterator_to_array(new MockProviderClient()->fetchTitles());

        self::assertNotEmpty(array_filter($titles, static fn (ProviderTitle $t): bool => $t->rating === null));
        self::assertNotEmpty(array_filter($titles, static fn (ProviderTitle $t): bool => $t->durationMinutes === null));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn (ProviderTitle $t): bool => $t->titleRu === null && $t->titleEn === null,
        ));
    }
}
