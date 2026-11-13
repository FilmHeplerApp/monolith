<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\DTOs\CatalogAttributePackData;
use App\Application\Import\DTOs\CatalogTitlePackData;
use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Infrastructure\Import\Exceptions\CatalogAttributeDefinitionException;
use App\Infrastructure\Import\Validation\CatalogAttributeDefinitionValidator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCatalogSchema;
use Tests\TestCase;

final class CatalogAttributeDefinitionValidatorTest extends TestCase
{
    use CreatesCatalogSchema;


    #[Test]
    public function it_reads_repeated_attribute_definitions_in_one_query(): void
    {
        $this->definitions('anime', ['genres', 'studios']);
        $title = $this->title('anime', ['genres', 'studios']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        new CatalogAttributeDefinitionValidator()->validate(array_fill(0, 100, $title));

        self::assertCount(1, DB::getQueryLog());
    }

    #[Test]
    public function it_does_not_query_when_there_are_no_attributes(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $validator = new CatalogAttributeDefinitionValidator;
        $validator->validate([]);
        $validator->validate([$this->title('anime', [])]);

        self::assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function it_bounds_query_size_for_large_sets_of_distinct_attributes(): void
    {
        $codes = array_map(static fn (int $index): string => 'attribute_'.$index, range(1, 501));
        $this->definitions('anime', $codes);
        DB::enableQueryLog();
        DB::flushQueryLog();

        new CatalogAttributeDefinitionValidator()->validate([$this->title('anime', $codes)]);

        self::assertCount(2, DB::getQueryLog());
    }

    #[Test]
    public function it_distinguishes_the_same_code_for_different_content_types(): void
    {
        $this->definitions('anime', ['genres']);
        $this->definitions('movie', ['genres'], 'string');

        new CatalogAttributeDefinitionValidator()->validate([
            $this->title('anime', ['genres']),
            $this->title('movie', ['genres'], AttributeValueType::STRING),
        ]);

        $this->expectException(CatalogAttributeDefinitionException::class);
        $this->expectExceptionMessage('Catalog attribute definition [movie:genres] has type [string], expected [array].');

        new CatalogAttributeDefinitionValidator()->validate([$this->title('movie', ['genres'])]);
    }

    #[Test]
    public function it_reports_a_missing_definition_for_the_requested_content_type(): void
    {
        $this->definitions('movie', ['genres']);
        $this->expectException(CatalogAttributeDefinitionException::class);
        $this->expectExceptionMessage('Missing catalog attribute definition [anime:genres].');

        new CatalogAttributeDefinitionValidator()->validate([$this->title('anime', ['genres'])]);
    }


    protected function setUp(): void
    {
        parent::setUp();
        $this->createCatalogSchema();
    }


    private function definitions(string $contentType, array $codes, string $valueType = 'array'): void
    {
        DB::table('attribute_definitions')->insert(array_map(static fn (string $code): array => [
            'content_type_code' => $contentType,
            'code' => $code,
            'name_ru' => $code,
            'name_en' => $code,
            'value_type' => $valueType,
        ], $codes));
    }

    private function title(string $contentType, array $codes, AttributeValueType $valueType = AttributeValueType::ARRAY): CatalogTitlePackData
    {
        return new CatalogTitlePackData(
            canonicalKey: TitleCanonicalKey::createFromString(str_repeat('a', 64)),
            title: LocalizedText::create(null, 'Example'),
            description: null,
            releaseYear: 2000,
            durationMinutes: null,
            contentType: TitleContentType::from($contentType),
            status: TitleStatus::RELEASED,
            posterUrl: null,
            bannerUrl: null,
            isIncomplete: true,
            attributes: array_map(static fn (string $code): CatalogAttributePackData => new CatalogAttributePackData($code, $valueType, []), $codes),
        );
    }
}
