<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers\Shikimori;

use App\Application\Import\DTOs\ProviderAttribute;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShikimoriTitleMapperTest extends TestCase
{
    #[Test]
    public function it_normalizes_a_record_without_a_transport_client(): void
    {
        $record = new ProviderTitleRecord(ProviderSource::Shikimori, '42', [
            'name' => 'Romanized name',
            'english' => ' ',
            'status' => 'anons',
            'kind' => 'movie',
            'score' => '0.0',
            'duration' => 0,
            'releasedOn' => ['year' => 2020],
            'genres' => [
                ['kind' => 'demographic', 'name' => 'Shounen'],
                ['kind' => 'theme', 'russian' => 'Школа'],
            ],
            'studios' => [['name' => 'Studio']],
            'scoresStats' => [['count' => 4], ['count' => 6]],
        ]);

        $title = (new ShikimoriTitleMapper)->map($record);

        self::assertSame(ProviderSource::Shikimori, $title->source);
        self::assertSame('42', $title->externalId);
        self::assertSame('Romanized name', $title->title?->getEn());
        self::assertSame('announced', $title->status);
        self::assertSame(TitleFormat::MOVIE, $title->format);
        self::assertSame(0.0, $title->rating);
        self::assertNull($title->durationMinutes);
        self::assertSame(2020, $title->year);
        self::assertSame(10, $title->ratingCount);
        self::assertSame(['target_audience', 'tags', 'studios'], array_column($title->attributes, 'code'));
        self::assertSame('Shounen', $title->attributes[0]->value?->getEn());
        self::assertSame('Школа', $title->attributes[1]->value?->getRu());
        self::assertSame('Studio', $title->attributes[2]->value?->getEn());
        self::assertSame('anons', $record->payload['status']);
    }

    #[Test]
    public function it_leaves_missing_optional_data_unknown(): void
    {
        $title = (new ShikimoriTitleMapper)->map(new ProviderTitleRecord(ProviderSource::Shikimori, '42', []));

        self::assertSame('42', $title->externalId);
        self::assertNull($title->title);
        self::assertNull($title->rating);
        self::assertNull($title->ratingCount);
        self::assertNull($title->format);
        self::assertSame([], $title->attributes);
    }

    #[Test]
    public function it_rejects_a_record_from_another_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ShikimoriTitleMapper)->map(new ProviderTitleRecord(ProviderSource::Jikan, '42', []));
    }

    #[Test]
    #[DataProvider('malformedCollections')]
    public function it_treats_collections_with_an_unexpected_shape_as_unknown(mixed $genres, mixed $studios, mixed $scoresStats): void
    {
        $title = $this->map(['genres' => $genres, 'studios' => $studios, 'scoresStats' => $scoresStats]);

        self::assertSame('42', $title->externalId);
        self::assertSame([], $title->attributes);
        self::assertNull($title->ratingCount);
    }

    public static function malformedCollections(): array
    {
        return [
            'strings' => ['action', 'bones', 'many'],
            'nulls' => [null, null, null],
            'scalars' => [1, true, 2.5],
            'objects instead of lists' => [['name' => 'Action'], ['name' => 'Bones'], ['count' => 3]],
            'items are not objects' => [['action', null, 5, [1, 2], []], ['bones'], ['10']],
        ];
    }

    #[Test]
    public function it_skips_only_malformed_items_inside_a_collection(): void
    {
        $title = $this->map([
            'genres' => ['broken', ['name' => 'Action', 'russian' => 'Экшен', 'kind' => 'genre'], null],
            'studios' => [42, ['name' => 'Bones']],
            'scoresStats' => ['broken', ['count' => 3], ['count' => 'many'], ['count' => '4']],
        ]);

        self::assertEquals(
            [
                new ProviderAttribute('genres', LocalizedText::create('Экшен', 'Action')),
                new ProviderAttribute('studios', LocalizedText::create(null, 'Bones')),
            ],
            $title->attributes,
        );
        self::assertSame(7, $title->ratingCount);
    }

    #[Test]
    public function it_maps_taxonomy_kinds_to_attribute_codes(): void
    {
        $title = $this->map(['genres' => [
            ['name' => 'Seinen', 'kind' => 'demographic'],
            ['name' => 'Isekai', 'kind' => 'theme'],
            ['name' => 'Drama'],
            ['name' => 'Broken', 'kind' => ['genre']],
            ['name' => 'Unknown', 'kind' => 'something_new'],
        ]]);

        self::assertSame(
            ['target_audience', 'tags', 'genres', 'genres', 'tags'],
            array_column($title->attributes, 'code'),
        );
    }

    #[Test]
    #[DataProvider('ratingCounts')]
    public function it_keeps_unknown_rating_counts_apart_from_zero(mixed $scoresStats, ?int $expected): void
    {
        self::assertSame($expected, $this->map(['scoresStats' => $scoresStats])->ratingCount);
    }

    public static function ratingCounts(): array
    {
        return [
            'explicit zero' => [[['score' => 8, 'count' => 0]], 0],
            'no statistics' => [[], null],
            'only unknown counts' => [[['score' => 8], ['count' => 'many'], ['count' => [3]]], null],
            'unknown counts are not zeros' => [[['count' => 'many'], ['count' => 2]], 2],
            'count beyond the int range' => [[['count' => '1e30'], ['count' => 2]], 2],
            'sum overflowing int' => [[['count' => PHP_INT_MAX], ['count' => 1]], null],
            'numeric string count' => [[['count' => '4'], ['count' => 3.0]], 7],
        ];
    }

    #[Test]
    public function it_leaves_an_unknown_kind_without_a_format(): void
    {
        self::assertNull($this->map(['kind' => 'something_new'])->format);
        self::assertSame(TitleFormat::TV_SPECIAL, $this->map(['kind' => ' TV_Special '])->format);
    }

    #[Test]
    #[DataProvider('malformedScalars')]
    public function it_treats_malformed_scalars_as_unknown(mixed $value): void
    {
        $title = $this->map([
            'status' => $value,
            'kind' => $value,
            'score' => $value,
            'duration' => $value,
            'airedOn' => ['year' => $value],
            'releasedOn' => ['year' => $value],
            'russian' => $value,
            'poster' => ['originalUrl' => $value],
        ]);

        self::assertSame('', $title->status);
        self::assertNull($title->format);
        self::assertNull($title->rating);
        self::assertNull($title->durationMinutes);
        self::assertNull($title->year);
        self::assertNull($title->title);
        self::assertNull($title->posterUrl);
    }

    public static function malformedScalars(): array
    {
        return [
            'list' => [['tv']],
            'object' => [['value' => 'released']],
            'bool' => [true],
            'empty string' => [''],
            'null' => [null],
        ];
    }

    #[Test]
    public function it_turns_non_numeric_text_into_unknown_numbers(): void
    {
        $title = $this->map(['score' => 'abc', 'duration' => 'long', 'airedOn' => ['year' => 'soon']]);

        self::assertNull($title->rating);
        self::assertNull($title->durationMinutes);
        self::assertNull($title->year);
    }

    #[Test]
    public function it_keeps_numeric_strings(): void
    {
        $title = $this->map(['score' => ' 7.5 ', 'duration' => '24', 'airedOn' => ['year' => 'soon'], 'releasedOn' => ['year' => '2009']]);

        self::assertSame(7.5, $title->rating);
        self::assertSame(24, $title->durationMinutes);
        self::assertSame(2009, $title->year);
    }

    #[Test]
    public function it_treats_numbers_beyond_the_int_range_as_unknown(): void
    {
        $title = $this->map(['duration' => '1e30', 'airedOn' => ['year' => -1e30], 'releasedOn' => ['year' => 2001]]);

        self::assertNull($title->durationMinutes);
        self::assertSame(2001, $title->year);
    }


    private function map(array $payload): ProviderTitle
    {
        return (new ShikimoriTitleMapper)->map(new ProviderTitleRecord(ProviderSource::Shikimori, '42', $payload));
    }
}
