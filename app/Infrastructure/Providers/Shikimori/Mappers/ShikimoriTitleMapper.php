<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Mappers;

use App\Application\Import\Contracts\ProviderTitleMapperContract;
use App\Application\Import\DTOs\ProviderAttribute;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderTitleMappingException;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Import\Enums\RejectionReason;
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
        foreach (['genres', 'studios', 'scoresStats'] as $field) {
            $items = $row[$field] ?? [];
            if (!is_array($items) || !array_is_list($items)) {
                throw new ProviderTitleMappingException(RejectionReason::MALFORMED, ['field' => $field], 'Expected a list of objects.');
            }
            foreach ($items as $item) {
                if (!is_array($item) || array_is_list($item)) {
                    throw new ProviderTitleMappingException(RejectionReason::MALFORMED, ['field' => $field], 'Expected an object.');
                }
            }
        }
        $status = strtolower(trim((string) ($row['status'] ?? '')));

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
            year: $this->nullableInt(data_get($row, 'airedOn.year') ?? data_get($row, 'releasedOn.year')),
            durationMinutes: $this->nullablePositiveInt($row['duration'] ?? null),
            rating: $this->nullableFloat($row['score'] ?? null),
            posterUrl: $this->cleanString(data_get($row, 'poster.originalUrl')),
            bannerUrl: null,
            type: TitleContentType::ANIME->value,
            status: $status === 'anons' ? 'announced' : $status,
            format: TitleFormat::tryFrom(strtolower(trim((string) ($row['kind'] ?? '')))),
            ratingCount: $this->ratingCount($row['scoresStats'] ?? []),
            attributes: [
                ...$this->mapTaxonomy($row['genres'] ?? []),
                ...$this->mapStudios($row['studios'] ?? []),
            ],
        );
    }


    private function mapTaxonomy(array $items): array
    {
        return array_values(array_map(
            fn (array $item): ProviderAttribute => new ProviderAttribute(
                code: match (strtolower(trim((string) ($item['kind'] ?? 'genre')))) {
                    'genre' => 'genres',
                    'demographic' => 'target_audience',
                    'theme' => 'tags',
                    default => 'tags',
                },
                value: LocalizedText::create(
                    $this->cleanString($item['russian'] ?? null),
                    $this->cleanString($item['name'] ?? null),
                ),
            ),
            $items,
        ));
    }


    private function mapStudios(array $studios): array
    {
        return array_values(array_map(
            fn (array $studio): ProviderAttribute => new ProviderAttribute(
                code: 'studios',
                value: LocalizedText::create(null, $this->cleanString($studio['name'] ?? null)),
            ),
            $studios,
        ));
    }


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
        if (!is_string($value)) {
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

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
