<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\Services;

use App\Application\Import\Services\CatalogTitlePackDataFactory;
use App\Application\Import\Services\ImportAttributeRegistry;
use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Domain\Import\DTOs\CandidateAttribute;
use App\Domain\Import\DTOs\KeyedTitleCandidate;
use App\Domain\Import\DTOs\TitleCandidate;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogTitlePackDataFactoryTest extends TestCase
{
    #[Test]
    public function it_transfers_candidate_fields_and_takes_value_types_from_the_registry(): void
    {
        $key = TitleCanonicalKey::createFromString('cowboy-bebop');
        $title = LocalizedText::create('Ковбой Бибоп', 'Cowboy Bebop');
        $description = LocalizedText::create('Описание', null);
        $genres = [LocalizedText::create('Экшен', 'Action'), LocalizedText::create(null, 'Drama')];
        $studios = [LocalizedText::create(null, 'Sunrise')];

        $packData = $this->factory()->create(new KeyedTitleCandidate($this->candidate(
            title: $title,
            description: $description,
            attributes: [new CandidateAttribute('genres', $genres), new CandidateAttribute('studios', $studios)],
        ), $key), true);

        self::assertSame($key, $packData->canonicalKey);
        self::assertSame($title, $packData->title);
        self::assertSame($description, $packData->description);
        self::assertSame(1998, $packData->releaseYear);
        self::assertSame(24, $packData->durationMinutes);
        self::assertSame(TitleContentType::ANIME, $packData->contentType);
        self::assertSame(TitleStatus::RELEASED, $packData->status);
        self::assertSame('https://example.test/poster.jpg', $packData->posterUrl);
        self::assertNull($packData->bannerUrl);
        self::assertTrue($packData->isIncomplete);
        self::assertCount(2, $packData->attributes);
        self::assertSame('genres', $packData->attributes[0]->code);
        self::assertSame(AttributeValueType::ARRAY, $packData->attributes[0]->valueType);
        self::assertSame($genres, $packData->attributes[0]->values);
        self::assertSame('studios', $packData->attributes[1]->code);
        self::assertSame(AttributeValueType::ARRAY, $packData->attributes[1]->valueType);
        self::assertSame($studios, $packData->attributes[1]->values);
    }

    #[Test]
    public function it_keeps_a_string_attribute_type_from_the_registry(): void
    {
        $packData = $this->factory()->create(new KeyedTitleCandidate($this->candidate(
            attributes: [new CandidateAttribute('pace', [LocalizedText::create('быстрый', 'fast')])],
        ), TitleCanonicalKey::createFromString('key')), false);

        self::assertFalse($packData->isIncomplete);
        self::assertSame(AttributeValueType::STRING, $packData->attributes[0]->valueType);
    }

    #[Test]
    public function it_refuses_an_attribute_code_missing_from_the_registry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Import attribute [anime:directors] is not registered.');

        $this->factory()->create(new KeyedTitleCandidate($this->candidate(
            attributes: [new CandidateAttribute('directors', [LocalizedText::create(null, 'Shinichiro Watanabe')])],
        ), TitleCanonicalKey::createFromString('key')), false);
    }

    #[Test]
    public function it_refuses_a_candidate_without_a_title(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Import candidate [shikimori:1] has no title.');

        $this->factory()->create(new KeyedTitleCandidate(
            $this->candidate(hasTitle: false),
            TitleCanonicalKey::createFromString('key'),
        ), false);
    }


    private function factory(): CatalogTitlePackDataFactory
    {
        return new CatalogTitlePackDataFactory(new ImportAttributeRegistry);
    }

    private function candidate(
        bool           $hasTitle = true,
        ?LocalizedText $title = null,
        ?LocalizedText $description = null,
        array          $attributes = [],
    ): TitleCandidate {
        return new TitleCandidate(
            source: 'shikimori',
            externalId: '1',
            title: $hasTitle ? ($title ?? LocalizedText::create('Тайтл', null)) : null,
            description: $description,
            type: TitleContentType::ANIME,
            status: TitleStatus::RELEASED,
            releaseYear: 1998,
            providerScore: 8.75,
            providerScoreCount: 100,
            durationMinutes: 24,
            posterUrl: 'https://example.test/poster.jpg',
            bannerUrl: null,
            attributes: $attributes,
        );
    }
}
