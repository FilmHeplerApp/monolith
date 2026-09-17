<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Mock;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderException;
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
        if ($this->failure !== null && $this->failAfter >= $limit) {
            throw new InvalidArgumentException(
                "Failure configured after {$this->failAfter} item(s), but the fetch limit is {$limit}; it would never be reached."
            );
        }

        return (function () use ($limit) {
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
        })();
    }

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitle
    {
        return array_find(
            $this->titles,
            static fn (ProviderTitle $title): bool => $title->externalId === $externalId,
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

    /** @return list<ProviderTitle> */
    private static function defaultFixtures(): array
    {
        return [
            new ProviderTitle(
                source: ProviderSource::Mock,
                externalId: '5114',
                titleRu: 'Стальной алхимик: Братство',
                titleEn: 'Fullmetal Alchemist: Brotherhood',
                description: 'Два брата ищут философский камень, чтобы вернуть тела.',
                genres: ['action', 'adventure', 'drama'],
                year: 2009,
                durationMinutes: 24,
                rating: 9.1,
                posterUrl: 'https://mock.local/posters/5114.jpg',
                bannerUrl: null,
                type: 'anime',
                status: 'released',
            ),
            new ProviderTitle(
                source: ProviderSource::Mock,
                externalId: '1',
                titleRu: 'Ковбой Бибоп',
                titleEn: 'Cowboy Bebop',
                description: 'Команда охотников за головами бороздит Солнечную систему.',
                genres: ['action', 'sci-fi'],
                year: 1998,
                durationMinutes: 24,
                rating: 8.8,
                posterUrl: 'https://mock.local/posters/1.jpg',
                bannerUrl: null,
                type: 'anime',
                status: 'released',
            ),
            new ProviderTitle(
                source: ProviderSource::Mock,
                externalId: '9253',
                titleRu: 'Врата Штейна',
                titleEn: 'Steins;Gate',
                description: 'Группа друзей случайно изобретает способ отправлять сообщения в прошлое.',
                genres: ['sci-fi', 'thriller'],
                year: 2011,
                durationMinutes: 24,
                rating: 9.0,
                posterUrl: 'https://mock.local/posters/9253.jpg',
                bannerUrl: null,
                type: 'anime',
                status: 'released',
            ),
            new ProviderTitle(
                source: ProviderSource::Mock,
                externalId: '52991',
                titleRu: 'Магическая битва',
                titleEn: 'Jujutsu Kaisen',
                description: 'Старшеклассник глотает проклятый артефакт и попадает в школу магов.',
                genres: ['action', 'fantasy'],
                year: 2023,
                durationMinutes: 24,
                rating: null,
                posterUrl: 'https://mock.local/posters/52991.jpg',
                bannerUrl: null,
                type: 'anime',
                status: 'ongoing',
            ),
            new ProviderTitle(
                source: ProviderSource::Mock,
                externalId: '48926',
                titleRu: null,
                titleEn: 'Bocchi the Rock!',
                description: null,
                genres: ['music', 'comedy'],
                year: 2022,
                durationMinutes: null,
                rating: null,
                posterUrl: 'https://mock.local/posters/48926.jpg',
                bannerUrl: null,
                type: 'anime',
                status: 'released',
            ),
            new ProviderTitle(
                source: ProviderSource::Mock,
                externalId: '30123',
                titleRu: 'Забытое старое кино',
                titleEn: null,
                description: null,
                genres: [],
                year: 1975,
                durationMinutes: null,
                rating: null,
                posterUrl: null,
                bannerUrl: null,
                type: 'anime',
                status: 'released',
            ),
            new ProviderTitle(
                source: ProviderSource::Mock,
                externalId: '58567',
                titleRu: null,
                titleEn: 'Untitled Announced Project',
                description: null,
                genres: [],
                year: null,
                durationMinutes: null,
                rating: null,
                posterUrl: null,
                bannerUrl: null,
                type: 'anime',
                status: 'announced',
            ),
            new ProviderTitle(
                source: ProviderSource::Mock,
                externalId: '999999',
                titleRu: null,
                titleEn: null,
                description: null,
                genres: [],
                year: null,
                durationMinutes: null,
                rating: null,
                posterUrl: null,
                bannerUrl: null,
                type: 'anime',
                status: 'released',
            ),
        ];
    }
}
