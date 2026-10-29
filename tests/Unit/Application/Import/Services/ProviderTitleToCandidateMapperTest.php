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
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
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
        self::assertSame('Hero description.', $candidate->description?->getEn());
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
    public function it_keeps_promotional_formats_for_the_evaluator(): void
    {
        self::assertSame(TitleFormat::PV, $this->mapper()->map($this->title(format: TitleFormat::PV))->format);
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

    #[Test]
    public function it_rejects_an_out_of_range_rating_as_malformed(): void
    {
        $this->expectException(ProviderTitleMappingException::class);

        try {
            $this->mapper()->map($this->title(rating: 11.0));
        } catch (ProviderTitleMappingException $exception) {
            self::assertSame(RejectionReason::MALFORMED, $exception->reason);
            self::assertSame(['rating' => 11.0], $exception->context);

            throw $exception;
        }
    }

    #[Test]
    public function it_rejects_a_non_positive_duration_as_malformed(): void
    {
        $this->expectException(ProviderTitleMappingException::class);

        try {
            $this->mapper()->map($this->title(durationMinutes: 0));
        } catch (ProviderTitleMappingException $exception) {
            self::assertSame(RejectionReason::MALFORMED, $exception->reason);
            self::assertSame(['durationMinutes' => 0], $exception->context);

            throw $exception;
        }
    }


    #[Test]
    public function it_accepts_internal_attribute_codes_without_provider_specific_mapping(): void
    {
        $original = $this->title();
        $title = new ProviderTitle(
            source: ProviderSource::Mock,
            externalId: $original->externalId,
            title: $original->title,
            description: $original->description,
            genres: [new ProviderTaxonomyItem('directors', null, 'Example director')],
            year: $original->year,
            durationMinutes: $original->durationMinutes,
            rating: $original->rating,
            posterUrl: $original->posterUrl,
            bannerUrl: null,
            type: 'movie',
            status: 'released',
        );

        self::assertSame('directors', $this->mapper()->map($title)->attributes[0]->code);
    }


    private function mapper(): ProviderTitleToCandidateMapper
    {
        return new ProviderTitleToCandidateMapper(new ProviderTitleNormalizer);
    }

    private function title(
        string       $type = 'anime',
        string       $status = 'announced',
        ?int         $durationMinutes = 24,
        ?float       $rating = 9.1,
        ?TitleFormat $format = TitleFormat::TV,
    ): ProviderTitle {
        return new ProviderTitle(
            source: ProviderSource::Shikimori,
            externalId: '5114',
            title: LocalizedText::create('Стальной алхимик', 'Fullmetal Alchemist'),
            description: LocalizedText::create(
                '[character=1]Описание[/character] <b>героя</b>.',
                '<p>Hero <strong>description</strong>.</p>',
            ),
            genres: [
                new ProviderTaxonomyItem('genres', 'Экшен', 'Action'),
                new ProviderTaxonomyItem('target_audience', 'Сёнен', 'Shounen'),
            ],
            year: 2009,
            durationMinutes: $durationMinutes,
            rating: $rating,
            posterUrl: 'https://example.test/poster.jpg',
            bannerUrl: null,
            type: $type,
            status: $status,
            format: $format,
            studios: [new ProviderTaxonomyItem('studios', null, 'Bones')],
            ratingCount: 1500,
        );
    }
}
