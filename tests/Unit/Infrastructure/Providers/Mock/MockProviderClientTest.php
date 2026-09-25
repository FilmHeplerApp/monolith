<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers\Mock;

use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderRateLimitedException;
use App\Application\Import\Exceptions\ProviderUnavailableException;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
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

    public function test_it_rejects_a_negative_failure_offset(): void
    {
        $client = new MockProviderClient;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failure offset must be >= 0, got -1.');

        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock), afterItems: -1);
    }

    public function test_it_does_not_interpret_failure_configuration_against_the_fetch_limit(): void
    {
        $client = new MockProviderClient;
        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock), afterItems: 2);

        $titles = iterator_to_array($client->fetchTitles(limit: 2));

        self::assertCount(2, $titles);
    }

    public function test_it_rejects_a_zero_limit_eagerly(): void
    {
        $client = new MockProviderClient;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fetch limit must be >= 1 or null, got 0.');

        $client->fetchTitles(limit: 0);
    }

    public function test_it_rejects_a_negative_limit_eagerly(): void
    {
        $client = new MockProviderClient;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fetch limit must be >= 1 or null, got -1.');

        $client->fetchTitles(limit: -1);
    }

    public function test_it_rejects_a_negative_offset_eagerly(): void
    {
        $client = new MockProviderClient;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fetch offset must be >= 0, got -1.');

        $client->fetchTitles(offset: -1);
    }

    public function test_default_fixtures_include_incomplete_titles(): void
    {
        $titles = iterator_to_array(new MockProviderClient()->fetchTitles());

        self::assertNotEmpty(array_filter($titles, static fn (ProviderTitle $t): bool => $t->rating === null));
        self::assertNotEmpty(array_filter($titles, static fn (ProviderTitle $t): bool => $t->durationMinutes === null));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn (ProviderTitle $title): bool => $title->title === null,
        ));
    }

    public function test_default_fixtures_include_localized_title_and_description(): void
    {
        $title = new MockProviderClient()->fetchTitleByExternalId('5114');

        self::assertNotNull($title);
        self::assertInstanceOf(LocalizedText::class, $title->title);
        self::assertSame('Стальной алхимик: Братство', $title->title->getRu());
        self::assertSame('Fullmetal Alchemist: Brotherhood', $title->title->getEn());
        self::assertInstanceOf(LocalizedText::class, $title->description);
        self::assertSame(
            'Два брата ищут философский камень, чтобы вернуть тела.',
            $title->description->getRu(),
        );
        self::assertSame(
            'Two brothers search for the Philosopher Stone to restore their bodies.',
            $title->description->getEn(),
        );
    }

    public function test_default_fixtures_include_invalid_titles(): void
    {
        $titles = iterator_to_array(new MockProviderClient()->fetchTitles());

        self::assertNotEmpty(array_filter(
            $titles,
            static fn (ProviderTitle $title): bool => $title->type === 'unknown',
        ));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn (ProviderTitle $title): bool => $title->status === 'unknown',
        ));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn (ProviderTitle $title): bool => $title->externalId === '',
        ));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn (ProviderTitle $title): bool => $title->rating !== null
                && ($title->rating < 0 || $title->rating > 10),
        ));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn (ProviderTitle $title): bool => $title->durationMinutes !== null
                && $title->durationMinutes <= 0,
        ));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn (ProviderTitle $title): bool => $title->description?->getRu() !== null
                && $title->description->getRu() !== strip_tags($title->description->getRu()),
        ));

        $externalIds = array_map(
            static fn (ProviderTitle $title): string => $title->externalId,
            $titles,
        );

        self::assertSame(2, array_count_values($externalIds)['5114']);
    }
}
