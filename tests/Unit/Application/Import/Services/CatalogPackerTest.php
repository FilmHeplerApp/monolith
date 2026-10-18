<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\Services;

use App\Application\Import\DTOs\CatalogAttributeDefinitionPackData;
use App\Application\Import\DTOs\CatalogAttributePackData;
use App\Application\Import\DTOs\CatalogPackInput;
use App\Application\Import\DTOs\CatalogTitlePackData;
use App\Application\Import\Services\CatalogPacker;
use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\Enums\Title\TitleUpdatedBy;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogPackerTest extends TestCase
{
    private const string CANONICAL_KEY = 'naruto';
    private const string SECOND_CANONICAL_KEY = 'bleach';


    #[Test]
    public function it_packs_a_title_into_writer_collections(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(
                attributes: [
                    $this->attribute('genres', [
                        $this->text('Экшен', 'Action'),
                        $this->text('Драма', 'Drama'),
                        $this->text('Экшен', 'Action'),
                    ]),
                    $this->attribute('studios', [$this->text('Pierrot', 'Pierrot')], AttributeValueType::STRING),
                ],
            )],
            attributeDefinitions: [
                $this->definition('genres', AttributeValueType::ARRAY, 1),
                $this->definition('studios', AttributeValueType::STRING, 2, isFilterable: false),
            ],
        ));

        self::assertCount(1, $packed->titles);
        self::assertCount(2, $packed->attributeDefinitions);
        self::assertCount(3, $packed->attributeOptions);
        self::assertCount(2, $packed->titleAttributes);

        $title = $packed->titles[0];
        self::assertSame(self::CANONICAL_KEY, $title->canonicalKey->getValue());
        self::assertSame('Наруто', $title->title->getRu());
        self::assertSame('Naruto', $title->title->getEn());
        self::assertSame('Описание', $title->description?->getRu());
        self::assertNull($title->shortPlotRu);
        self::assertSame(24, $title->duration?->getMinutes());
        self::assertSame(2002, $title->releaseYear?->getYear());
        self::assertSame(TitleContentType::ANIME, $title->type);
        self::assertSame(TitleStatus::RELEASED, $title->status);
        self::assertSame('https://example.test/poster.jpg', $title->posterUrl);
        self::assertSame('https://example.test/banner.jpg', $title->bannerUrl);
        self::assertFalse($title->isIncomplete);
        self::assertSame(TitleUpdatedBy::PROCESS, $title->updatedBy);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $title->uuid->getValue(),
        );

        self::assertSame('genres', $packed->attributeDefinitions[0]->code);
        self::assertSame(TitleContentType::ANIME, $packed->attributeDefinitions[0]->contentTypeCode);
        self::assertSame(AttributeValueType::ARRAY, $packed->attributeDefinitions[0]->type);
        self::assertSame(1, $packed->attributeDefinitions[0]->order);

        self::assertSame(TitleContentType::ANIME, $packed->attributeOptions[0]->contentTypeCode);
        self::assertSame('Экшен', $packed->attributeOptions[0]->value->getRu());
        self::assertSame('Action', $packed->attributeOptions[0]->value->getEn());
        self::assertSame('Драма', $packed->attributeOptions[1]->value->getRu());
        self::assertSame('studios', $packed->attributeOptions[2]->attributeCode);

        self::assertSame(['Экшен', 'Драма'], $packed->titleAttributes[0]->value->getArray());
        self::assertSame(TitleContentType::ANIME, $packed->titleAttributes[0]->contentTypeCode);
        self::assertSame('Pierrot', $packed->titleAttributes[1]->value->getText());
        self::assertSame(TitleContentType::ANIME, $packed->titleAttributes[1]->contentTypeCode);
    }

    #[Test]
    public function it_reuses_one_uuid_for_the_same_canonical_key(): void
    {
        $first = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title()],
            attributeDefinitions: [],
        ));
        $second = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(isIncomplete: true)],
            attributeDefinitions: [],
        ));

        self::assertSame($first->titles[0]->uuid->getValue(), $second->titles[0]->uuid->getValue());
        self::assertTrue($second->titles[0]->isIncomplete);
        self::assertNull($second->titles[0]->description);
    }

    #[Test]
    public function it_deduplicates_options_and_identical_definitions_across_titles(): void
    {
        $genres = $this->definition('genres', AttributeValueType::ARRAY, 1);
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [
                $this->title(attributes: [$this->attribute('genres', [$this->text('Экшен', 'Action')])]),
                $this->title(
                    canonicalKey: self::SECOND_CANONICAL_KEY,
                    titleRu: 'Блич',
                    titleEn: 'Bleach',
                    attributes: [$this->attribute('genres', [$this->text('Экшен', 'Action film')])],
                ),
            ],
            attributeDefinitions: [$genres, $genres],
        ));

        self::assertCount(1, $packed->attributeDefinitions);
        self::assertCount(1, $packed->attributeOptions);
        self::assertSame('Action', $packed->attributeOptions[0]->value->getEn());
        self::assertCount(2, $packed->titleAttributes);
        self::assertSame(self::SECOND_CANONICAL_KEY, $packed->titleAttributes[1]->titleCanonicalKey->getValue());
    }

    #[Test]
    public function it_keeps_an_unused_definition(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [],
            attributeDefinitions: [$this->definition('genres', AttributeValueType::ARRAY, 1)],
        ));

        self::assertSame([], $packed->titles);
        self::assertCount(1, $packed->attributeDefinitions);
        self::assertSame([], $packed->attributeOptions);
        self::assertSame([], $packed->titleAttributes);
    }

    #[Test]
    public function it_skips_an_attribute_without_values(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [$this->attribute('genres', [])])],
            attributeDefinitions: [$this->definition('genres', AttributeValueType::ARRAY, 1)],
        ));

        self::assertSame([], $packed->attributeOptions);
        self::assertSame([], $packed->titleAttributes);
    }

    #[Test]
    public function it_merges_a_repeated_array_attribute(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('genres', [$this->text('Экшен', 'Action')]),
                $this->attribute('genres', [$this->text('Экшен', 'Action'), $this->text('Драма', 'Drama')]),
            ])],
            attributeDefinitions: [$this->definition('genres', AttributeValueType::ARRAY, 1)],
        ));

        self::assertCount(1, $packed->titleAttributes);
        self::assertSame(['Экшен', 'Драма'], $packed->titleAttributes[0]->value->getArray());
    }

    #[Test]
    public function it_rejects_a_duplicate_canonical_key(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate canonical key [naruto] in one catalog pack.');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(), $this->title()],
            attributeDefinitions: [],
        ));
    }

    #[Test]
    public function it_packs_an_attribute_when_definitions_are_absent(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [$this->attribute('genres', [$this->text('Экшен', 'Action')])])],
            attributeDefinitions: [],
        ));

        self::assertSame([], $packed->attributeDefinitions);
        self::assertCount(1, $packed->attributeOptions);
        self::assertCount(1, $packed->titleAttributes);
        self::assertSame(['Экшен'], $packed->titleAttributes[0]->value->getArray());
        self::assertSame(TitleContentType::ANIME, $packed->titleAttributes[0]->contentTypeCode);
    }

    #[Test]
    public function it_rejects_an_attribute_value_type_that_disagrees_with_a_definition(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute [anime:studios] value type [array] does not match [string] in one catalog pack.');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [$this->attribute('studios', [$this->text('Pierrot')])])],
            attributeDefinitions: [$this->definition('studios', AttributeValueType::STRING, 2)],
        ));
    }

    #[Test]
    public function it_rejects_conflicting_attribute_value_types(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute [anime:genres] value type [string] does not match [array] in one catalog pack.');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [
                $this->title(attributes: [$this->attribute('genres', [$this->text('Экшен')])]),
                $this->title(
                    canonicalKey: self::SECOND_CANONICAL_KEY,
                    attributes: [$this->attribute('genres', [$this->text('Pierrot')], AttributeValueType::STRING)],
                ),
            ],
            attributeDefinitions: [],
        ));
    }

    #[Test]
    public function it_rejects_conflicting_definitions(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute definition [anime:genres] has conflicting metadata in one catalog pack.');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [],
            attributeDefinitions: [
                $this->definition('genres', AttributeValueType::ARRAY, 1),
                $this->definition('genres', AttributeValueType::ARRAY, 2),
            ],
        ));
    }

    #[Test]
    public function it_rejects_several_values_for_a_string_attribute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute [anime:studios] of type [string] must contain exactly one value.');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('studios', [$this->text('Pierrot'), $this->text('Bones')], AttributeValueType::STRING),
            ])],
            attributeDefinitions: [$this->definition('studios', AttributeValueType::STRING, 2)],
        ));
    }

    #[Test]
    public function it_rejects_a_repeated_string_attribute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute [anime:studios] is declared twice for canonical key [naruto].');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('studios', [$this->text('Pierrot')], AttributeValueType::STRING),
                $this->attribute('studios', [$this->text('Bones')], AttributeValueType::STRING),
            ])],
            attributeDefinitions: [$this->definition('studios', AttributeValueType::STRING, 2)],
        ));
    }

    #[Test]
    public function it_collapses_a_repeated_scalar_attribute_with_the_same_value(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('studios', [$this->text('Pierrot')], AttributeValueType::STRING),
                $this->attribute('studios', [$this->text('Pierrot')], AttributeValueType::STRING),
                $this->attribute('score', [$this->text('8.5')], AttributeValueType::NUMBER),
                $this->attribute('score', [$this->text('8.50')], AttributeValueType::NUMBER),
                $this->attribute('ongoing', [$this->text('true')], AttributeValueType::BOOLEAN),
                $this->attribute('ongoing', [$this->text('1')], AttributeValueType::BOOLEAN),
            ])],
            attributeDefinitions: [],
        ));

        self::assertCount(3, $packed->titleAttributes);
        self::assertSame('Pierrot', $packed->titleAttributes[0]->value->getText());
        self::assertSame(8.5, $packed->titleAttributes[1]->value->getNumber());
        self::assertTrue($packed->titleAttributes[2]->value->getBoolean());
        self::assertSame(
            ['Pierrot'],
            array_map(static fn ($option) => $option->value->getRu(), $packed->attributeOptions),
        );
    }

    #[Test]
    public function it_rejects_a_repeated_number_attribute_with_a_different_value(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute [anime:score] is declared twice for canonical key [naruto].');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('score', [$this->text('8')], AttributeValueType::NUMBER),
                $this->attribute('score', [$this->text('9')], AttributeValueType::NUMBER),
            ])],
            attributeDefinitions: [],
        ));
    }

    #[Test]
    public function it_rejects_a_repeated_boolean_attribute_with_a_different_value(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute [anime:ongoing] is declared twice for canonical key [naruto].');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('ongoing', [$this->text('true')], AttributeValueType::BOOLEAN),
                $this->attribute('ongoing', [$this->text('false')], AttributeValueType::BOOLEAN),
            ])],
            attributeDefinitions: [],
        ));
    }

    #[Test]
    public function it_packs_a_number_attribute(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('score', [$this->text('8.5')], AttributeValueType::NUMBER),
            ])],
            attributeDefinitions: [$this->definition('score', AttributeValueType::NUMBER, 3)],
        ));

        self::assertSame([], $packed->attributeOptions);
        self::assertSame(8.5, $packed->titleAttributes[0]->value->getNumber());
        self::assertNull($packed->titleAttributes[0]->value->getText());
        self::assertNull($packed->titleAttributes[0]->value->getBoolean());
    }

    #[Test]
    public function it_packs_a_boolean_attribute(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('ongoing', [$this->text('false')], AttributeValueType::BOOLEAN),
            ])],
            attributeDefinitions: [$this->definition('ongoing', AttributeValueType::BOOLEAN, 4)],
        ));

        self::assertSame([], $packed->attributeOptions);
        self::assertFalse($packed->titleAttributes[0]->value->getBoolean());
        self::assertNull($packed->titleAttributes[0]->value->getText());
        self::assertNull($packed->titleAttributes[0]->value->getNumber());
    }

    #[Test]
    public function it_rejects_several_values_for_a_number_attribute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute [anime:score] of type [number] must contain exactly one value.');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('score', [$this->text('8'), $this->text('9')], AttributeValueType::NUMBER),
            ])],
            attributeDefinitions: [],
        ));
    }

    #[Test]
    public function it_rejects_a_non_numeric_attribute_value(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute [anime:score] value [fast] is not a number.');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('score', [$this->text('fast')], AttributeValueType::NUMBER),
            ])],
            attributeDefinitions: [],
        ));
    }

    #[Test]
    public function it_rejects_a_non_boolean_attribute_value(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute [anime:ongoing] value [yes] is not a boolean.');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('ongoing', [$this->text('yes')], AttributeValueType::BOOLEAN),
            ])],
            attributeDefinitions: [],
        ));
    }

    #[Test]
    public function it_rejects_an_unpacked_value_type(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute value type [enum] is not packed.');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [$this->attribute('format', [$this->text('TV')], AttributeValueType::ENUM)])],
            attributeDefinitions: [$this->definition('format', AttributeValueType::ENUM, 5)],
        ));
    }

    #[Test]
    public function it_packs_an_empty_input(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [],
            attributeDefinitions: [],
        ));

        self::assertSame([], $packed->titles);
        self::assertSame([], $packed->attributeDefinitions);
        self::assertSame([], $packed->attributeOptions);
        self::assertSame([], $packed->titleAttributes);
    }

    #[Test]
    public function it_packs_null_year_duration_and_poster(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(releaseYear: null, durationMinutes: null, posterUrl: null)],
            attributeDefinitions: [],
        ));

        self::assertNull($packed->titles[0]->releaseYear);
        self::assertNull($packed->titles[0]->duration);
        self::assertNull($packed->titles[0]->posterUrl);
        self::assertSame('https://example.test/banner.jpg', $packed->titles[0]->bannerUrl);
    }

    #[Test]
    public function it_packs_an_english_only_attribute_value(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [$this->attribute('genres', [$this->text(null, 'Action')])])],
            attributeDefinitions: [],
        ));

        self::assertNull($packed->attributeOptions[0]->value->getRu());
        self::assertSame('Action', $packed->attributeOptions[0]->value->getEn());
        self::assertSame(['Action'], $packed->titleAttributes[0]->value->getArray());
    }

    #[Test]
    public function it_packs_boolean_true_and_one(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('ongoing', [$this->text('true')], AttributeValueType::BOOLEAN),
                $this->attribute('featured', [$this->text('1')], AttributeValueType::BOOLEAN),
            ])],
            attributeDefinitions: [],
        ));

        self::assertTrue($packed->titleAttributes[0]->value->getBoolean());
        self::assertTrue($packed->titleAttributes[1]->value->getBoolean());
        self::assertSame([], $packed->attributeOptions);
    }

    #[Test]
    public function it_rejects_a_non_finite_number(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Attribute [anime:score] value [INF] is not a number.');

        (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->title(attributes: [
                $this->attribute('score', [$this->text('INF')], AttributeValueType::NUMBER),
            ])],
            attributeDefinitions: [],
        ));
    }

    #[Test]
    public function it_packs_the_same_code_for_two_content_types(): void
    {
        $packed = (new CatalogPacker)->pack(new CatalogPackInput(
            titles: [
                $this->title(attributes: [$this->attribute('genres', [$this->text('Экшен')])]),
                $this->title(
                    canonicalKey: self::SECOND_CANONICAL_KEY,
                    contentType: TitleContentType::MOVIE,
                    attributes: [$this->attribute('genres', [$this->text('Драма')])],
                ),
            ],
            attributeDefinitions: [
                $this->definition('genres', AttributeValueType::ARRAY, 1),
                $this->definition('genres', AttributeValueType::ARRAY, 1, TitleContentType::MOVIE),
            ],
        ));

        self::assertCount(2, $packed->attributeDefinitions);
        self::assertSame(TitleContentType::ANIME, $packed->attributeDefinitions[0]->contentTypeCode);
        self::assertSame(TitleContentType::MOVIE, $packed->attributeDefinitions[1]->contentTypeCode);
        self::assertCount(2, $packed->attributeOptions);
        self::assertSame(TitleContentType::ANIME, $packed->attributeOptions[0]->contentTypeCode);
        self::assertSame(TitleContentType::MOVIE, $packed->attributeOptions[1]->contentTypeCode);
        self::assertCount(2, $packed->titleAttributes);
        self::assertSame(['Экшен'], $packed->titleAttributes[0]->value->getArray());
        self::assertSame(['Драма'], $packed->titleAttributes[1]->value->getArray());
        self::assertSame(TitleContentType::MOVIE, $packed->titleAttributes[1]->contentTypeCode);
    }


    /**
     * @param  list<CatalogAttributePackData>  $attributes
     */
    private function title(
        string           $canonicalKey = self::CANONICAL_KEY,
        string           $titleRu = 'Наруто',
        string           $titleEn = 'Naruto',
        ?LocalizedText   $description = null,
        bool             $isIncomplete = false,
        ?int             $releaseYear = 2002,
        ?int             $durationMinutes = 24,
        ?string          $posterUrl = 'https://example.test/poster.jpg',
        TitleContentType $contentType = TitleContentType::ANIME,
        array            $attributes = [],
    ): CatalogTitlePackData {
        return new CatalogTitlePackData(
            canonicalKey: TitleCanonicalKey::createFromString($canonicalKey),
            title: $this->text($titleRu, $titleEn),
            description: $description ?? ($isIncomplete ? null : $this->text('Описание', 'Description')),
            releaseYear: $releaseYear,
            durationMinutes: $durationMinutes,
            contentType: $contentType,
            status: TitleStatus::RELEASED,
            posterUrl: $posterUrl,
            bannerUrl: 'https://example.test/banner.jpg',
            isIncomplete: $isIncomplete,
            attributes: $attributes,
        );
    }

    /**
     * @param  list<LocalizedText>  $values
     */
    private function attribute(
        string             $code,
        array              $values,
        AttributeValueType $valueType = AttributeValueType::ARRAY,
    ): CatalogAttributePackData {
        return new CatalogAttributePackData($code, $valueType, $values);
    }

    private function definition(
        string             $code,
        AttributeValueType $type,
        int                $order,
        TitleContentType   $contentType = TitleContentType::ANIME,
        bool               $isFilterable = true,
    ): CatalogAttributeDefinitionPackData {
        return new CatalogAttributeDefinitionPackData(
            code: $code,
            name: $this->text($code, $code),
            contentType: $contentType,
            valueType: $type,
            isFilterable: $isFilterable,
            isRequired: false,
            order: $order,
        );
    }

    private function text(?string $ru, ?string $en = null): LocalizedText
    {
        return LocalizedText::create($ru, $en) ?? throw new LogicException('Test text is empty.');
    }
}
