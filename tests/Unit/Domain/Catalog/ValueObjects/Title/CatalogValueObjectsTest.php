<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\ValueObjects\Title;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\Embedding;
use App\Domain\Catalog\ValueObjects\Title\ExternalId;
use App\Domain\Catalog\ValueObjects\Title\TitleRating;
use App\Domain\Catalog\ValueObjects\TitleAttribute\AttributeValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogValueObjectsTest extends TestCase
{
    #[Test]
    public function external_id_normalizes_uuid(): void
    {
        $id = ExternalId::createFromString('018FE2F8-0A2E-7A42-B0A7-6F6F5B0F0F13');

        $this->assertSame('018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f13', $id->getValue());
    }

    #[Test]
    public function external_id_rejects_invalid_uuid(): void
    {
        $this->expectException(InvalidCatalogValueException::class);

        ExternalId::createFromString('not-a-uuid');
    }

    #[Test]
    public function localized_text_returns_null_when_blank(): void
    {
        $text = LocalizedText::create('Название', null);

        $this->assertSame('Название', $text?->getPreferred('ru'));
        $this->assertNull(LocalizedText::create(null, null));
        $this->assertNull(LocalizedText::create('  ', null));
    }

    #[Test]
    public function title_rating_rejects_out_of_range_average(): void
    {
        $this->expectException(InvalidCatalogValueException::class);

        TitleRating::create(10.1, 1);
    }

    #[Test]
    public function embedding_requires_exact_dimension(): void
    {
        $vector = array_fill(0, Embedding::DIMENSION, 0.1);

        $this->assertCount(Embedding::DIMENSION, Embedding::createFromArray($vector)?->getVector() ?? []);
        $this->assertNull(Embedding::createFromArray(null));

        $this->expectException(InvalidCatalogValueException::class);
        Embedding::createFromArray([0.1, 0.2]);
    }

    #[Test]
    public function attribute_value_allows_only_one_typed_payload(): void
    {
        $value = AttributeValue::createFromRaw(array: ['психологический', 'триллер']);

        $this->assertSame('психологический триллер', $value->getSearchableText());

        $this->expectException(InvalidCatalogValueException::class);
        AttributeValue::createFromRaw(text: 'medium', number: 1.0);
    }
}
