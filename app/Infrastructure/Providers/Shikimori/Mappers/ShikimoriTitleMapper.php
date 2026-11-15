<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Mappers;

use App\Application\Import\Contracts\ProviderTitleMapperContract;
use App\Application\Import\DTOs\ProviderAttribute;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use InvalidArgumentException;

final readonly class ShikimoriTitleMapper implements ProviderTitleMapperContract
{
    public function source(): ProviderSource
    {
        return ProviderSource::Shikimori;
    }

    public function map(ProviderTitleRecord $record): ProviderTitle
    {
        if ($record->source !== $this->source()) {
            throw new InvalidArgumentException('Shikimori mapper requires a Shikimori record.');
        }
        $row = $record->payload;
        $status = strtolower($this->cleanString($row['status'] ?? null) ?? '');
        $kind = $this->cleanString($row['kind'] ?? null);

        return new ProviderTitle(
            source: ProviderSource::Shikimori,
            externalId: $record->externalId,
            title: LocalizedText::create(
                $this->cleanString($row['russian'] ?? null),
                $this->cleanString($row['english'] ?? null) ?? $this->cleanString($row['name'] ?? null),
            ),
            description: LocalizedText::create(
                $this->cleanString($row['description'] ?? null),
                null,
            ),
            year: $this->nullableInt(data_get($row, 'airedOn.year')) ?? $this->nullableInt(data_get($row, 'releasedOn.year')),
            durationMinutes: $this->nullablePositiveInt($row['duration'] ?? null),
            rating: $this->nullableFloat($row['score'] ?? null),
            posterUrl: $this->cleanString(data_get($row, 'poster.originalUrl')),
            bannerUrl: null,
            type: TitleContentType::ANIME->value,
            status: $status === 'anons' ? 'announced' : $status,
            format: $kind === null ? null : TitleFormat::tryFrom(strtolower($kind)),
            ratingCount: $this->ratingCount($row['scoresStats'] ?? []),
            attributes: [
                ...$this->mapTaxonomy($row['genres'] ?? []),
                ...$this->mapStudios($row['studios'] ?? []),
            ],
        );
    }


    private function mapTaxonomy(mixed $items): array
    {
        $attributes = [];

        foreach ($this->objectsOf($items) as $item) {
            $attributes[] = new ProviderAttribute(
                code: match (strtolower($this->cleanString($item['kind'] ?? null) ?? 'genre')) {
                    'genre' => 'genres',
                    'demographic' => 'target_audience',
                    default => 'tags',
                },
                value: LocalizedText::create(
                    $this->cleanString($item['russian'] ?? null),
                    $this->cleanString($item['name'] ?? null),
                ),
            );
        }

        return $attributes;
    }


    private function mapStudios(mixed $studios): array
    {
        $attributes = [];

        foreach ($this->objectsOf($studios) as $studio) {
            $attributes[] = new ProviderAttribute(
                code: 'studios',
                value: LocalizedText::create(null, $this->cleanString($studio['name'] ?? null)),
            );
        }

        return $attributes;
    }


    private function ratingCount(mixed $stats): ?int
    {
        $counts = array_filter(
            array_map(fn (array $stat): ?int => $this->nullableInt($stat['count'] ?? null), $this->objectsOf($stats)),
            static fn (?int $count): bool => $count !== null,
        );

        $sum = array_sum($counts);

        // An int overflow turns the sum into a float: the total is then unknown.
        return $counts === [] || !is_int($sum) ? null : $sum;
    }

    /**
     * Keeps the JSON objects of a list; any other shape is treated as unknown data.
     *
     * @return list<array<string, mixed>>
     */
    private function objectsOf(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_array($item) && $item !== [] && !array_is_list($item),
        ));
    }

    private function cleanString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $number = $this->nullableFloat($value);

        // Outside the int range a cast is meaningless: treat the value as unknown.
        return $number === null || $number < PHP_INT_MIN || $number >= PHP_INT_MAX ? null : (int) $number;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = $this->nullableInt($value);

        return $value !== null && $value > 0 ? $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        $number = match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && is_numeric(trim($value)) => (float) trim($value),
            default => null,
        };

        return $number !== null && is_finite($number) ? $number : null;
    }
}
