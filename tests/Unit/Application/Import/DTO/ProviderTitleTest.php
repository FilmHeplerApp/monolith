<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\DTO;

use App\Application\Import\DTO\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderTitleTest extends TestCase
{
    public function test_it_builds_a_valid_title(): void
    {
        $title = self::makeTitle();

        self::assertSame(ProviderSource::Mock, $title->source);
        self::assertSame('5114', $title->externalId);
        self::assertSame('Стальной алхимик', $title->titleRu);
        self::assertSame(['action', 'drama'], $title->genres);
        self::assertSame(9.1, $title->rating);
    }

    public function test_it_allows_a_title_with_only_one_language(): void
    {
        $ruOnly = self::makeTitle(titleEn: null);
        $enOnly = self::makeTitle(titleRu: null);

        self::assertNull($ruOnly->titleEn);
        self::assertNull($enOnly->titleRu);
    }

    public function test_it_allows_null_optional_fields(): void
    {
        $title = self::makeTitle(durationMinutes: null, rating: null);

        self::assertNull($title->rating);
        self::assertNull($title->durationMinutes);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidPayloads')]
    public function test_it_rejects_invalid_payloads(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::makeTitle(...$overrides);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidPayloads(): array
    {
        return [
            'empty external id' => [['externalId' => '']],
            'blank external id' => [['externalId' => '   ']],
            'no ru and no en title' => [['titleRu' => null, 'titleEn' => null]],
            'both titles blank' => [['titleRu' => '', 'titleEn' => '   ']],
            'rating above ten' => [['rating' => 11.0]],
            'rating below zero' => [['rating' => -0.5]],
            'zero duration' => [['durationMinutes' => 0]],
            'negative duration' => [['durationMinutes' => -5]],
        ];
    }

    /** @param list<string> $genres */
    private static function makeTitle(
        ProviderSource $source = ProviderSource::Mock,
        string $externalId = '5114',
        ?string $titleRu = 'Стальной алхимик',
        ?string $titleEn = 'Fullmetal Alchemist',
        ?string $description = 'Two brothers search for a Philosopher\'s Stone.',
        array $genres = ['action', 'drama'],
        ?int $year = 2009,
        ?int $durationMinutes = 24,
        ?float $rating = 9.1,
        ?string $posterUrl = 'https://mock.local/posters/5114.jpg',
        ?string $bannerUrl = null,
        string $type = 'anime',
        string $status = 'released',
    ): ProviderTitle {
        return new ProviderTitle(
            source: $source,
            externalId: $externalId,
            titleRu: $titleRu,
            titleEn: $titleEn,
            description: $description,
            genres: $genres,
            year: $year,
            durationMinutes: $durationMinutes,
            rating: $rating,
            posterUrl: $posterUrl,
            bannerUrl: $bannerUrl,
            type: $type,
            status: $status,
        );
    }
}
