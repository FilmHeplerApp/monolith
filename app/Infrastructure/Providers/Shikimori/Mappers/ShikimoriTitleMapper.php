<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Mappers;

use App\Application\Import\DTOs\ProviderTaxonomyItem;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;

final readonly class ShikimoriTitleMapper
{
    /** @param array<string, mixed> $row */
    public function map(array $row): ProviderTitle
    {
        return new ProviderTitle(
            source: ProviderSource::Shikimori,
            externalId: (string) ($row['id'] ?? ''),
            title: LocalizedText::create(
                $this->cleanString($row['russian'] ?? null),
                $this->cleanString($row['english'] ?? null) ?? $this->cleanString($row['name'] ?? null),
            ),
            description: LocalizedText::create(
                $this->cleanString($row['description'] ?? null),
                null,
            ),
            genres: $this->mapTaxonomy($row['genres'] ?? []),
            studios: $this->mapStudios($row['studios'] ?? []),
            year: $this->nullableInt(data_get($row, 'airedOn.year') ?? data_get($row, 'releasedOn.year')),
            durationMinutes: $this->nullablePositiveInt($row['duration'] ?? null),
            rating: $this->nullablePositiveFloat($row['score'] ?? null),
            ratingCount: $this->ratingCount($row['scoresStats'] ?? []),
            posterUrl: $this->cleanString(data_get($row, 'poster.originalUrl')),
            bannerUrl: null,
            type: (string) ($row['kind'] ?? ''),
            status: (string) ($row['status'] ?? ''),
        );
    }


    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<ProviderTaxonomyItem>
     */
    private function mapTaxonomy(array $items): array
    {
        return array_values(array_map(
            fn (array $item): ProviderTaxonomyItem => new ProviderTaxonomyItem(
                kind: (string) ($item['kind'] ?? 'genre'),
                nameRu: $this->cleanString($item['russian'] ?? null),
                nameEn: $this->cleanString($item['name'] ?? null),
            ),
            $items,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $studios
     * @return list<ProviderTaxonomyItem>
     */
    private function mapStudios(array $studios): array
    {
        return array_values(array_map(
            fn (array $studio): ProviderTaxonomyItem => new ProviderTaxonomyItem(
                kind: 'studio',
                nameRu: null,
                nameEn: $this->cleanString($studio['name'] ?? null),
            ),
            $studios,
        ));
    }

    /** @param list<array<string, mixed>> $stats */
    private function ratingCount(array $stats): ?int
    {
        if ($stats === []) {
            return null;
        }

        return array_sum(array_map(
            static fn (array $stat): int => (int) ($stat['count'] ?? 0),
            $stats,
        ));
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = $this->nullableInt($value);

        return $value !== null && $value > 0 ? $value : null;
    }

    private function nullablePositiveFloat(mixed $value): ?float
    {
        $value = (float) ($value ?? 0);

        return $value > 0 ? $value : null;
    }
}
