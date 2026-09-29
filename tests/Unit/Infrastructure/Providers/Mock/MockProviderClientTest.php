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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MockProviderClientTest extends TestCase
{
    #[Test]
    public function it_reports_its_source(): void
    {
        self::assertSame(ProviderSource::Mock, new MockProviderClient()->source());
    }

    #[Test]
    public function it_yields_provider_titles(): void
    {
        $titles = iterator_to_array(new MockProviderClient()->fetchTitles());

        self::assertNotEmpty($titles);
        self::assertContainsOnlyInstancesOf(ProviderTitle::class, $titles);
    }

    #[Test]
    public function it_respects_the_limit(): void
    {
        $titles = iterator_to_array(new MockProviderClient()->fetchTitles(limit: 2));

        self::assertCount(2, $titles);
    }

    #[Test]
    public function it_finds_a_title_by_external_id(): void
    {
        $client = new MockProviderClient;

        $found = $client->fetchTitleByExternalId('5114');

        self::assertNotNull($found);
        self::assertSame('5114', $found->externalId);
        self::assertSame('Стальной алхимик: Братство', $found->title?->getRu());
    }

    #[Test]
    public function it_returns_null_for_unknown_external_id(): void
    {
        self::assertNull(new MockProviderClient()->fetchTitleByExternalId('nope'));
    }

    #[Test]
    public function it_raises_a_rate_limit_only_while_iterating(): void
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

    #[Test]
    public function it_raises_unavailability_only_while_iterating(): void
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

    #[Test]
    public function it_raises_immediate_failure_from_lookup(): void
    {
        $client = new MockProviderClient;
        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock));

        $this->expectException(ProviderUnavailableException::class);

        $client->fetchTitleByExternalId('5114');
    }

    #[Test]
    public function it_keeps_lookup_available_when_the_stream_fails_later(): void
    {
        $client = new MockProviderClient;
        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock), afterItems: 2);

        $found = $client->fetchTitleByExternalId('5114');

        self::assertNotNull($found);
        self::assertSame('5114', $found->externalId);
    }

    #[Test]
    public function it_rejects_a_failure_beyond_available_titles(): void
    {
        $client = new MockProviderClient;

        $this->expectException(InvalidArgumentException::class);

        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock), afterItems: 999);
    }

    #[Test]
    public function it_rejects_a_negative_failure_offset(): void
    {
        $client = new MockProviderClient;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failure offset must be >= 0, got -1.');

        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock), afterItems: -1);
    }

    #[Test]
    public function it_does_not_interpret_failure_configuration_against_the_fetch_limit(): void
    {
        $client = new MockProviderClient;
        $client->failWith(new ProviderUnavailableException(ProviderSource::Mock), afterItems: 2);

        $titles = iterator_to_array($client->fetchTitles(limit: 2));

        self::assertCount(2, $titles);
    }

    #[Test]
    public function it_rejects_a_zero_limit_eagerly(): void
    {
        $client = new MockProviderClient;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fetch limit must be >= 1, got 0.');

        $client->fetchTitles(limit: 0);
    }

    #[Test]
    public function it_rejects_a_negative_limit_eagerly(): void
    {
        $client = new MockProviderClient;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fetch limit must be >= 1, got -1.');

        $client->fetchTitles(limit: -1);
    }

    #[Test]
    public function it_includes_incomplete_titles_in_the_default_fixtures(): void
    {
        $titles = $this->defaultTitlesByExternalId();

        $bocchi = $titles['48926'];
        self::assertNull($bocchi->title?->getRu());
        self::assertSame('Bocchi the Rock!', $bocchi->title?->getEn());
        self::assertNull($bocchi->description);
        self::assertNull($bocchi->durationMinutes);
        self::assertNull($bocchi->rating);

        $forgotten = $titles['30123'];
        self::assertSame('Забытое старое кино', $forgotten->title?->getRu());
        self::assertNull($forgotten->title?->getEn());
        self::assertSame([], $forgotten->genres);
        self::assertNull($forgotten->rating);
        self::assertNull($forgotten->durationMinutes);

        $announced = $titles['58567'];
        self::assertNull($announced->year);
        self::assertSame('announced', $announced->status);
        self::assertNull($announced->rating);

        $empty = $titles['999999'];
        self::assertNull($empty->title);
        self::assertNull($empty->description);
        self::assertNull($empty->year);
        self::assertNull($empty->durationMinutes);
        self::assertNull($empty->rating);
    }

    #[Test]
    public function it_includes_localized_title_and_description_in_the_default_fixtures(): void
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

    #[Test]
    public function it_includes_invalid_titles_in_the_default_fixtures(): void
    {
        $titles = iterator_to_array(new MockProviderClient()->fetchTitles());

        self::assertNotEmpty(array_filter(
            $titles,
            static fn(ProviderTitle $title): bool => $title->type === 'unknown',
        ));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn(ProviderTitle $title): bool => $title->status === 'unknown',
        ));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn(ProviderTitle $title): bool => $title->externalId === '',
        ));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn(ProviderTitle $title): bool => $title->rating !== null
                && ($title->rating < 0 || $title->rating > 10),
        ));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn(ProviderTitle $title): bool => $title->durationMinutes !== null
                && $title->durationMinutes <= 0,
        ));
        self::assertNotEmpty(array_filter(
            $titles,
            static fn(ProviderTitle $title): bool => $title->description?->getRu() !== null
                && $title->description->getRu() !== strip_tags($title->description->getRu()),
        ));

        $externalIds = array_map(
            static fn(ProviderTitle $title): string => $title->externalId,
            $titles,
        );

        self::assertSame(2, array_count_values($externalIds)['5114']);
    }

    #[Test]
    public function it_yields_the_duplicated_external_id_before_invalid_titles(): void
    {
        $externalIds = array_map(
            static fn(ProviderTitle $title): string => $title->externalId,
            iterator_to_array(new MockProviderClient()->fetchTitles()),
        );

        $duplicateAt = array_keys($externalIds, '5114', true)[1];
        $firstInvalidAt = array_search('900001', $externalIds, true);

        self::assertIsInt($firstInvalidAt);
        self::assertLessThan($firstInvalidAt, $duplicateAt);
    }

    #[Test]
    public function it_yields_injected_titles_instead_of_the_default_fixtures(): void
    {
        $custom = new ProviderTitle(
            source: ProviderSource::Mock,
            externalId: 'custom-1',
            title: LocalizedText::create('Свой тайтл', 'Custom title'),
            description: null,
            genres: ['custom'],
            year: 2020,
            durationMinutes: 100,
            rating: 6.0,
            posterUrl: null,
            bannerUrl: null,
            type: 'anime',
            status: 'released',
        );

        $client = new MockProviderClient([$custom]);
        $titles = iterator_to_array($client->fetchTitles());

        self::assertCount(1, $titles);
        self::assertSame('custom-1', $titles[0]->externalId);
        self::assertNull($client->fetchTitleByExternalId('5114'));
    }


    /**
     * @return array<string, ProviderTitle>
     */
    private function defaultTitlesByExternalId(): array
    {
        $titles = [];

        foreach (new MockProviderClient()->fetchTitles() as $title) {
            $titles[$title->externalId] = $title;
        }

        return $titles;
    }
}
