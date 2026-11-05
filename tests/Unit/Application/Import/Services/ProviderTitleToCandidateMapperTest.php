<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\Services;

use App\Application\Import\DTOs\ProviderAttribute;
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
            year: $original->year,
            durationMinutes: $original->durationMinutes,
            rating: $original->rating,
            posterUrl: $original->posterUrl,
            bannerUrl: null,
            type: 'movie',
            status: 'released',
            attributes: [new ProviderAttribute('directors', LocalizedText::create(null, 'Example director'))],
        );

        self::assertSame('directors', $this->mapper()->map($title)->attributes[0]->code);
    }


    #[Test]
    public function it_maps_arbitrary_attribute_sets_without_a_fixed_list_of_codes(): void
    {
        $attributes = [];
        for ($index = 0; $index < 100; $index++) {
            $attributes[] = new ProviderAttribute('custom_'.$index, LocalizedText::create(null, 'Value '.$index));
        }
        $attributes[] = new ProviderAttribute('123', LocalizedText::create('Числовой код', null));

        $candidate = $this->mapper()->map($this->title(attributes: $attributes));

        self::assertCount(101, $candidate->attributes);
        foreach ($attributes as $index => $attribute) {
            self::assertSame($attribute->code, $candidate->attributes[$index]->code);
            self::assertSame([$attribute->value], $candidate->attributes[$index]->values);
        }
    }

    #[Test]
    public function it_groups_and_deduplicates_values_within_each_attribute_code(): void
    {
        $candidate = $this->mapper()->map($this->title(attributes: [
            new ProviderAttribute('directors', LocalizedText::create(' Режиссёр ', ' Director ')),
            new ProviderAttribute('countries', LocalizedText::create(null, 'Japan')),
            new ProviderAttribute('directors', LocalizedText::create('Режиссёр', 'Director')),
            new ProviderAttribute('directors', LocalizedText::create(null, 'Another director')),
            new ProviderAttribute('producers', LocalizedText::create('Режиссёр', 'Director')),
        ]));

        self::assertSame(['directors', 'countries', 'producers'], array_column($candidate->attributes, 'code'));
        self::assertCount(2, $candidate->attributes[0]->values);
        self::assertSame('Director', $candidate->attributes[0]->values[0]->getEn());
        self::assertSame('Another director', $candidate->attributes[0]->values[1]->getEn());
        self::assertCount(1, $candidate->attributes[2]->values);
    }

    #[Test]
    public function it_omits_attributes_without_values(): void
    {
        $candidate = $this->mapper()->map($this->title(attributes: [
            new ProviderAttribute('directors', null),
            new ProviderAttribute('countries', LocalizedText::create(' ', ' ')),
        ]));

        self::assertSame([], $candidate->attributes);
        self::assertSame([], $this->mapper()->map($this->title(attributes: []))->attributes);
    }

    #[Test]
    public function it_rejects_invalid_attribute_entries_at_the_provider_title_boundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider title attributes must contain ProviderAttribute instances.');

        $this->title(attributes: ['directors' => 'Director']);
    }

    #[Test]
    public function it_rejects_blank_attribute_codes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProviderAttribute(' ', LocalizedText::create(null, 'Value'));
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
        ?array       $attributes = null,
    ): ProviderTitle {
        return new ProviderTitle(
            source: ProviderSource::Shikimori,
            externalId: '5114',
            title: LocalizedText::create('Стальной алхимик', 'Fullmetal Alchemist'),
            description: LocalizedText::create(
                '[character=1]Описание[/character] <b>героя</b>.',
                '<p>Hero <strong>description</strong>.</p>',
            ),
            year: 2009,
            durationMinutes: $durationMinutes,
            rating: $rating,
            posterUrl: 'https://example.test/poster.jpg',
            bannerUrl: null,
            type: $type,
            status: $status,
            format: $format,
            ratingCount: 1500,
            attributes: $attributes ?? [
                new ProviderAttribute('genres', LocalizedText::create('Экшен', 'Action')),
                new ProviderAttribute('target_audience', LocalizedText::create('Сёнен', 'Shounen')),
                new ProviderAttribute('studios', LocalizedText::create(null, 'Bones')),
            ],
        );
    }
}
