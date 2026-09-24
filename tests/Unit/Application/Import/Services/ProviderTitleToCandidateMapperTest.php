<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\Services;

use App\Application\Import\DTOs\ProviderTaxonomyItem;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderTitleMappingException;
use App\Application\Import\Services\ProviderTitleNormalizer;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Import\Enums\RejectionReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderTitleToCandidateMapperTest extends TestCase
{
    #[Test]
    public function it_maps_and_normalizes_provider_data(): void
    {
        $candidate = $this->mapper()->map($this->title());

        self::assertSame('shikimori', $candidate->source);
        self::assertSame('5114', $candidate->externalId);
        self::assertSame('Стальной алхимик', $candidate->title?->getRu());
        self::assertSame('Fullmetal Alchemist', $candidate->title?->getEn());
        self::assertSame('Описание героя.', $candidate->description?->getRu());
        self::assertSame(TitleContentType::ANIME, $candidate->type);
        self::assertSame(TitleStatus::ANNOUNCED, $candidate->status);
        self::assertSame(2009, $candidate->releaseYear);
        self::assertSame(9.1, $candidate->providerScore);
        self::assertSame(1500, $candidate->providerScoreCount);
        self::assertSame(
            ['genres', 'target_audience', 'studios'],
            array_map(static fn ($attribute): string => $attribute->code, $candidate->attributes),
        );
    }

    #[Test]
    public function it_rejects_promotional_shikimori_entries(): void
    {
        $this->expectException(ProviderTitleMappingException::class);

        try {
            $this->mapper()->map($this->title(type: 'pv'));
        } catch (ProviderTitleMappingException $exception) {
            self::assertSame(RejectionReason::UNSUPPORTED_TYPE, $exception->reason);

            throw $exception;
        }
    }

    #[Test]
    public function it_rejects_an_unknown_status_as_malformed(): void
    {
        $this->expectException(ProviderTitleMappingException::class);

        try {
            $this->mapper()->map($this->title(status: 'mystery'));
        } catch (ProviderTitleMappingException $exception) {
            self::assertSame(RejectionReason::MALFORMED, $exception->reason);

            throw $exception;
        }
    }

    private function mapper(): ProviderTitleToCandidateMapper
    {
        return new ProviderTitleToCandidateMapper(new ProviderTitleNormalizer);
    }

    private function title(string $type = 'tv', string $status = 'anons'): ProviderTitle
    {
        return new ProviderTitle(
            source: ProviderSource::Shikimori,
            externalId: '5114',
            titleRu: 'Стальной алхимик',
            titleEn: 'Fullmetal Alchemist',
            description: '[character=1]Описание[/character] <b>героя</b>.',
            genres: [
                new ProviderTaxonomyItem('genre', 'Экшен', 'Action'),
                new ProviderTaxonomyItem('demographic', 'Сёнен', 'Shounen'),
            ],
            studios: [new ProviderTaxonomyItem('studio', null, 'Bones')],
            year: 2009,
            durationMinutes: 24,
            rating: 9.1,
            ratingCount: 1500,
            posterUrl: 'https://example.test/poster.jpg',
            bannerUrl: null,
            type: $type,
            status: $status,
        );
    }
}
