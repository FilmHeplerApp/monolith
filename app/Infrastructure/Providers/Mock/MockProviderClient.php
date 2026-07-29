<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Mock;

use App\Domain\Import\Contracts\CatalogProviderClient;
use App\Domain\Import\Enums\ProviderSource;
use App\Domain\Import\Exceptions\ProviderException;
use App\Domain\Import\ValueObjects\ProviderTitle;

final class MockProviderClient implements CatalogProviderClient
{
    /** @var list<ProviderTitle> */
    private array $titles;

    private ?ProviderException $failure = null;

    private int $failAfter = 0;

    /** @param  list<ProviderTitle>|null  $titles */
    public function __construct(?array $titles = null)
    {
        $this->titles = $titles ?? self::defaultFixtures();
    }

    public function source(): ProviderSource
    {
        return ProviderSource::Mock;
    }

    /** @return iterable<ProviderTitle> */
    public function fetchTitles(int $limit = 50): iterable
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

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitle
    {
        return array_find(
            $this->titles,
            static fn (ProviderTitle $title): bool => $title->externalId === $externalId,
        );
    }

    public function failWith(ProviderException $exception, int $afterItems = 0): void
    {
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
        ];
    }
}
