<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Mock;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderException;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use Generator;
use InvalidArgumentException;

final class MockProviderClient implements ProviderClientContract
{
    /** @var list<ProviderTitle> */
    private array $titles;

    private ?ProviderException $failure = null;

    private int $failAfter = 0;


    /** @param list<ProviderTitle>|null $titles */
    public function __construct(?array $titles = null)
    {
        $this->titles = $titles ?? self::defaultFixtures();
    }

    public function source(): ProviderSource
    {
        return ProviderSource::Mock;
    }

    public function fetchTitles(int $limit = 50): Generator
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("Fetch limit must be >= 1, got {$limit}.");
        }

        return $this->iterateTitles($limit);
    }

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitle
    {
        return array_find(
            $this->titles,
            static fn(ProviderTitle $title): bool => $title->externalId === $externalId,
        );
    }

    public function failWith(ProviderException $exception, int $afterItems = 0): void
    {
        $available = count($this->titles);

        if ($afterItems < 0) {
            throw new InvalidArgumentException("Failure offset must be >= 0, got {$afterItems}.");
        }

        if ($afterItems >= $available) {
            throw new InvalidArgumentException(
                "Failure configured after {$afterItems} item(s), but the mock holds only {$available}; it would never be reached."
            );
        }

        $this->failure = $exception;
        $this->failAfter = $afterItems;
    }


    /** @return Generator<int, ProviderTitle> */
    private function iterateTitles(int $limit): Generator
    {
        $yielded = 0;

        foreach ($this->titles as $title) {
            if ($yielded >= $limit) {
                return;
            }

            if ($this->failure !== null && $yielded >= $this->failAfter) {
                throw $this->failure;
            }

            yield $title;
            $yielded++;
        }
    }

    /** @return list<ProviderTitle> */
    private static function defaultFixtures(): array
    {
        return [
            ...self::fixturesWithoutDuplicatedExternalId(),
            self::fixtureWithDuplicatedExternalId(),
        ];
    }

    /** @return list<ProviderTitle> */
    private static function fixturesWithoutDuplicatedExternalId(): array
    {
        return [
            ...self::completeFixtures(),
            ...self::incompleteFixtures(),
            ...self::invalidFixtures(),
        ];
    }

    /** @return list<ProviderTitle> */
    private static function completeFixtures(): array
    {
        return [
            self::fixture(
                externalId: '5114',
                titleRu: 'Стальной алхимик: Братство',
                titleEn: 'Fullmetal Alchemist: Brotherhood',
                descriptionRu: 'Два брата ищут философский камень, чтобы вернуть тела.',
                descriptionEn: 'Two brothers search for the Philosopher Stone to restore their bodies.',
                genres: ['action', 'adventure', 'drama'],
                year: 2009,
                rating: 9.1,
                posterUrl: 'https://mock.local/posters/5114.jpg',
            ),
            self::fixture(
                externalId: '1',
                titleRu: 'Ковбой Бибоп',
                titleEn: 'Cowboy Bebop',
                descriptionRu: 'Команда охотников за головами бороздит Солнечную систему.',
                genres: ['action', 'sci-fi'],
                year: 1998,
                rating: 8.8,
                posterUrl: 'https://mock.local/posters/1.jpg',
            ),
            self::fixture(
                externalId: '9253',
                titleRu: 'Врата Штейна',
                titleEn: 'Steins;Gate',
                descriptionRu: 'Группа друзей случайно изобретает способ отправлять сообщения в прошлое.',
                genres: ['sci-fi', 'thriller'],
                year: 2011,
                rating: 9.0,
                posterUrl: 'https://mock.local/posters/9253.jpg',
            ),
            self::fixture(
                externalId: '52991',
                titleRu: 'Магическая битва',
                titleEn: 'Jujutsu Kaisen',
                descriptionRu: 'Старшеклассник глотает проклятый артефакт и попадает в школу магов.',
                genres: ['action', 'fantasy'],
                year: 2023,
                rating: null,
                posterUrl: 'https://mock.local/posters/52991.jpg',
                status: 'ongoing',
            ),
        ];
    }

    /** @return list<ProviderTitle> */
    private static function incompleteFixtures(): array
    {
        return [
            self::fixture(
                externalId: '48926',
                titleRu: null,
                titleEn: 'Bocchi the Rock!',
                descriptionRu: null,
                genres: ['music', 'comedy'],
                year: 2022,
                durationMinutes: null,
                rating: null,
                posterUrl: 'https://mock.local/posters/48926.jpg',
            ),
            self::fixture(
                externalId: '30123',
                titleRu: 'Забытое старое кино',
                titleEn: null,
                descriptionRu: null,
                genres: [],
                year: 1975,
                durationMinutes: null,
                rating: null,
            ),
            self::fixture(
                externalId: '58567',
                titleRu: null,
                titleEn: 'Untitled Announced Project',
                descriptionRu: null,
                genres: [],
                year: null,
                durationMinutes: null,
                rating: null,
                status: 'announced',
            ),
            self::fixture(
                externalId: '999999',
                titleRu: null,
                titleEn: null,
                descriptionRu: null,
                genres: [],
                year: null,
                durationMinutes: null,
                rating: null,
            ),
        ];
    }

    /** @return list<ProviderTitle> */
    private static function invalidFixtures(): array
    {
        return [
            self::fixtureWithUnknownType(),
            self::fixtureWithUnknownStatus(),
            self::fixtureWithEmptyExternalId(),
            self::fixtureWithOutOfRangeRating(),
            self::fixtureWithNonPositiveDuration(),
            self::fixtureWithHtmlDescription(),
        ];
    }

    private static function fixtureWithUnknownType(): ProviderTitle
    {
        return self::fixture(externalId: '900001', type: 'unknown');
    }

    private static function fixtureWithUnknownStatus(): ProviderTitle
    {
        return self::fixture(externalId: '900002', status: 'unknown');
    }

    private static function fixtureWithEmptyExternalId(): ProviderTitle
    {
        return self::fixture(externalId: '');
    }

    private static function fixtureWithOutOfRangeRating(): ProviderTitle
    {
        return self::fixture(externalId: '900003', rating: 11.0);
    }

    private static function fixtureWithNonPositiveDuration(): ProviderTitle
    {
        return self::fixture(externalId: '900004', durationMinutes: 0);
    }

    private static function fixtureWithHtmlDescription(): ProviderTitle
    {
        return self::fixture(
            externalId: '900005',
            descriptionRu: '<p>Описание со <strong>встроенным HTML</strong>.</p>',
        );
    }

    private static function fixtureWithDuplicatedExternalId(): ProviderTitle
    {
        return self::fixture(
            externalId: '5114',
            titleRu: 'Дубликат Стального алхимика',
            titleEn: 'Fullmetal Alchemist Duplicate',
        );
    }

    /** @param list<string> $genres */
    private static function fixture(
        string  $externalId,
        ?string $titleRu = 'Тестовый тайтл',
        ?string $titleEn = 'Test title',
        ?string $descriptionRu = 'Тестовое описание.',
        ?string $descriptionEn = null,
        array   $genres = ['test'],
        ?int    $year = 2024,
        ?int    $durationMinutes = 24,
        ?float  $rating = 7.5,
        ?string $posterUrl = null,
        ?string $bannerUrl = null,
        string  $type = 'anime',
        string  $status = 'released',
    ): ProviderTitle {
        return new ProviderTitle(
            source: ProviderSource::Mock,
            externalId: $externalId,
            title: LocalizedText::create($titleRu, $titleEn),
            description: LocalizedText::create($descriptionRu, $descriptionEn),
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
