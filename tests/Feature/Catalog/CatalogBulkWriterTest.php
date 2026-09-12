<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Application\Catalog\Contracts\CatalogBulkWriter;
use App\Application\Catalog\DTOs\AttributeDefinitionData;
use App\Application\Catalog\DTOs\AttributeOptionData;
use App\Application\Catalog\DTOs\PreparedCatalogDto;
use App\Application\Catalog\DTOs\TitleAttributeData;
use App\Application\Catalog\DTOs\TitleData;
use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\Enums\Title\TitleUpdatedBy;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\Duration;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Domain\Catalog\ValueObjects\Title\TitleUuid;
use App\Domain\Catalog\ValueObjects\TitleAttribute\AttributeValue;
use App\Infrastructure\Persistence\Eloquent\Bulk\Catalog\UnresolvedCatalogReferenceException;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeDefinition;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeOption;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\TitleAttribute;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogBulkWriterTest extends TestCase
{
    private const string TITLE_UUID = '018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f13';

    private const string SECOND_TITLE_UUID = '018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f14';

    private const string CANONICAL_KEY = 'naruto|anime';

    private const string SECOND_CANONICAL_KEY = 'bleach|anime';

    private const string ATTRIBUTE_GENRES = 'genres';

    private const string ATTRIBUTE_STUDIOS = 'studios';

    private const string TITLE_RU = 'Наруто';

    private const string TITLE_EN = 'Naruto';

    private const string GENRE_ACTION_RU = 'Экшен';

    private const string GENRE_ACTION_EN = 'Action';

    private const string GENRE_DRAMA_RU = 'Драма';

    private const string STUDIO_NAME = 'Pierrot';

    private const int DURATION_MINUTES = 24;

    private const int EXPECTED_DEFINITION_COUNT = 2;

    private const int EXPECTED_OPTION_COUNT = 2;

    private const int EXPECTED_TITLE_ATTRIBUTE_COUNT = 2;


    private CatalogBulkWriter $writer;


    /**
     * @throws BindingResolutionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCatalogSchema();
        $this->writer = $this->app->make(CatalogBulkWriter::class);
    }


    #[Test]
    public function it_inserts_catalog_collections_and_resolves_foreign_keys(): void
    {
        $this->writer->write($this->preparedCatalog());

        $this->assertDatabaseCount(Title::TABLE_NAME, 1);
        $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, self::EXPECTED_DEFINITION_COUNT);
        $this->assertDatabaseCount(AttributeOption::TABLE_NAME, self::EXPECTED_OPTION_COUNT);
        $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, self::EXPECTED_TITLE_ATTRIBUTE_COUNT);

        $title = Title::query()->where(Title::FIELD_UUID, self::TITLE_UUID)->firstOrFail();
        $genres = AttributeDefinition::query()
            ->where(AttributeDefinition::FIELD_CODE, self::ATTRIBUTE_GENRES)
            ->firstOrFail();
        $studios = AttributeDefinition::query()
            ->where(AttributeDefinition::FIELD_CODE, self::ATTRIBUTE_STUDIOS)
            ->firstOrFail();

        $this->assertSame(self::TITLE_RU, $title->{Title::FIELD_TITLE_RU});
        $this->assertSame(self::TITLE_EN, $title->{Title::FIELD_TITLE_EN});
        $this->assertSame(self::DURATION_MINUTES, $title->{Title::FIELD_DURATION});
        $this->assertSame(self::CANONICAL_KEY, $title->{Title::FIELD_CANONICAL_KEY});
        $this->assertSame(TitleContentType::ANIME, $title->{Title::FIELD_TYPE});
        $this->assertSame(TitleUpdatedBy::PROCESS, $title->{Title::FIELD_UPDATED_BY});

        $this->assertDatabaseHas(AttributeOption::TABLE_NAME, [
            AttributeOption::FIELD_ATTRIBUTE_ID => $genres->{AttributeDefinition::FIELD_ID},
            AttributeOption::FIELD_VALUE_RU => self::GENRE_ACTION_RU,
            AttributeOption::FIELD_VALUE_EN => self::GENRE_ACTION_EN,
        ]);

        $genreValues = TitleAttribute::query()
            ->where(TitleAttribute::FIELD_TITLE_ID, $title->{Title::FIELD_ID})
            ->where(TitleAttribute::FIELD_ATTRIBUTE_ID, $genres->{AttributeDefinition::FIELD_ID})
            ->firstOrFail();

        $this->assertSame(
            [self::GENRE_ACTION_RU, self::GENRE_DRAMA_RU],
            $genreValues->{TitleAttribute::FIELD_VALUE_ARRAY},
        );
        $this->assertSame(
            self::GENRE_ACTION_RU . ' ' . self::GENRE_DRAMA_RU,
            $genreValues->{TitleAttribute::FIELD_SEARCHABLE_TEXT},
        );

        $studioValue = TitleAttribute::query()
            ->where(TitleAttribute::FIELD_TITLE_ID, $title->{Title::FIELD_ID})
            ->where(TitleAttribute::FIELD_ATTRIBUTE_ID, $studios->{AttributeDefinition::FIELD_ID})
            ->firstOrFail();

        $this->assertSame(self::STUDIO_NAME, $studioValue->{TitleAttribute::FIELD_VALUE_TEXT});
        $this->assertSame(self::STUDIO_NAME, $studioValue->{TitleAttribute::FIELD_SEARCHABLE_TEXT});
    }

    #[Test]
    public function it_upserts_existing_rows_without_creating_duplicates(): void
    {
        $this->writer->write($this->preparedCatalog());

        $this->writer->write($this->preparedCatalog(
            titleRu: 'Наруто: обновлённый',
            optionValueEn: 'Action updated',
            studioName: 'Studio Pierrot',
            studioOptionEn: 'Pierrot EN',
        ));

        $this->assertDatabaseCount(Title::TABLE_NAME, 1);
        $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, self::EXPECTED_DEFINITION_COUNT);
        $this->assertDatabaseCount(AttributeOption::TABLE_NAME, self::EXPECTED_OPTION_COUNT);
        $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, self::EXPECTED_TITLE_ATTRIBUTE_COUNT);

        $title = Title::query()->where(Title::FIELD_UUID, self::TITLE_UUID)->firstOrFail();

        $this->assertSame('Наруто: обновлённый', $title->{Title::FIELD_TITLE_RU});

        $this->assertDatabaseHas(AttributeOption::TABLE_NAME, [
            AttributeOption::FIELD_VALUE_RU => self::GENRE_ACTION_RU,
            AttributeOption::FIELD_VALUE_EN => 'Action updated',
        ]);

        $this->assertDatabaseHas(TitleAttribute::TABLE_NAME, [
            TitleAttribute::FIELD_VALUE_TEXT => 'Studio Pierrot',
            TitleAttribute::FIELD_SEARCHABLE_TEXT => 'Studio Pierrot',
        ]);
    }

    #[Test]
    public function it_resolves_definitions_already_stored_when_dto_definitions_are_empty(): void
    {
        $this->writer->write($this->preparedCatalog());

        $secondUuid = TitleUuid::createFromString(self::SECOND_TITLE_UUID);

        $this->writer->write(new PreparedCatalogDto(
            titles: [$this->createTitleData(
                $secondUuid,
                'Блич',
                'Bleach',
                self::SECOND_CANONICAL_KEY,
            )],
            attributeOptions: [
                new AttributeOptionData(self::ATTRIBUTE_GENRES, $this->createLocalizedText('Комедия', 'Comedy')),
            ],
            titleAttributes: [
                new TitleAttributeData(
                    $secondUuid,
                    self::ATTRIBUTE_GENRES,
                    AttributeValue::createFromArray(['Комедия']),
                ),
            ],
        ));

        $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, self::EXPECTED_DEFINITION_COUNT);
        $this->assertDatabaseCount(Title::TABLE_NAME, 2);
        $this->assertDatabaseHas(AttributeOption::TABLE_NAME, [
            AttributeOption::FIELD_VALUE_RU => 'Комедия',
            AttributeOption::FIELD_VALUE_EN => 'Comedy',
        ]);
    }

    #[Test]
    public function it_rolls_back_when_attribute_definition_is_missing(): void
    {
        $this->expectException(UnresolvedCatalogReferenceException::class);

        try {
            $this->writer->write(new PreparedCatalogDto(
                titles: [$this->createTitleData(TitleUuid::createFromString(self::TITLE_UUID))],
                titleAttributes: [
                    new TitleAttributeData(
                        TitleUuid::createFromString(self::TITLE_UUID),
                        'missing',
                        AttributeValue::createFromText('nope'),
                    ),
                ],
            ));
        } finally {
            $this->assertDatabaseCount(Title::TABLE_NAME, 0);
            $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, 0);
        }
    }


    private function preparedCatalog(
        string $titleRu = self::TITLE_RU,
        string $optionValueEn = self::GENRE_ACTION_EN,
        string $studioName = self::STUDIO_NAME,
        string $studioOptionEn = self::STUDIO_NAME,
    ): PreparedCatalogDto {
        $uuid = TitleUuid::createFromString(self::TITLE_UUID);

        return new PreparedCatalogDto(
            titles: [$this->createTitleData($uuid, $titleRu, self::TITLE_EN)],
            attributeDefinitions: [
                new AttributeDefinitionData(
                    self::ATTRIBUTE_GENRES,
                    $this->createLocalizedText('Жанры', 'Genres'),
                    TitleContentType::ANIME,
                    AttributeValueType::ARRAY,
                    true,
                    false,
                    1,
                ),
                new AttributeDefinitionData(
                    self::ATTRIBUTE_STUDIOS,
                    $this->createLocalizedText('Студия', 'Studio'),
                    TitleContentType::ANIME,
                    AttributeValueType::STRING,
                    false,
                    false,
                    2,
                ),
            ],
            attributeOptions: [
                new AttributeOptionData(self::ATTRIBUTE_GENRES, $this->createLocalizedText(self::GENRE_ACTION_RU, $optionValueEn)),
                new AttributeOptionData(self::ATTRIBUTE_STUDIOS, $this->createLocalizedText(self::STUDIO_NAME, $studioOptionEn)),
            ],
            titleAttributes: [
                new TitleAttributeData(
                    $uuid,
                    self::ATTRIBUTE_GENRES,
                    AttributeValue::createFromArray([self::GENRE_ACTION_RU, self::GENRE_DRAMA_RU]),
                ),
                new TitleAttributeData(
                    $uuid,
                    self::ATTRIBUTE_STUDIOS,
                    AttributeValue::createFromText($studioName),
                ),
            ],
        );
    }

    private function createTitleData(
        TitleUuid $uuid,
        string    $titleRu = self::TITLE_RU,
        string    $titleEn = self::TITLE_EN,
        string    $canonicalKey = self::CANONICAL_KEY,
    ): TitleData {
        return new TitleData(
            $uuid,
            TitleCanonicalKey::createFromString($canonicalKey),
            $this->createLocalizedText($titleRu, $titleEn),
            $this->createLocalizedText('Описание', 'Description'),
            'Короткий сюжет',
            Duration::createFromMinutes(self::DURATION_MINUTES),
            TitleContentType::ANIME,
            TitleStatus::RELEASED,
            'https://example.test/poster.jpg',
            'https://example.test/banner.jpg',
        );
    }

    private function createLocalizedText(string $ru, ?string $en = null): LocalizedText
    {
        $text = LocalizedText::create($ru, $en);
        $this->assertNotNull($text);

        return $text;
    }

    private function createCatalogSchema(): void
    {
        Schema::create(AttributeDefinition::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->string(AttributeDefinition::FIELD_CONTENT_TYPE_CODE);
            $table->string(AttributeDefinition::FIELD_CODE)->unique();
            $table->string(AttributeDefinition::FIELD_NAME_RU);
            $table->string(AttributeDefinition::FIELD_NAME_EN);
            $table->string(AttributeDefinition::FIELD_VALUE_TYPE);
            $table->boolean(AttributeDefinition::FIELD_IS_FILTERABLE)->default(true);
            $table->boolean(AttributeDefinition::FIELD_IS_REQUIRED)->default(false);
            $table->unsignedInteger(AttributeDefinition::FIELD_ORDER)->default(0);
            $table->timestamps();
        });

        Schema::create(Title::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->uuid(Title::FIELD_UUID)->unique();
            $table->string(Title::FIELD_CANONICAL_KEY)->unique();
            $table->string(Title::FIELD_TITLE_RU)->nullable();
            $table->string(Title::FIELD_TITLE_EN)->nullable();
            $table->text(Title::FIELD_DESCRIPTION_RU)->nullable();
            $table->text(Title::FIELD_DESCRIPTION_EN)->nullable();
            $table->text(Title::FIELD_SHORT_PLOT_RU)->nullable();
            $table->unsignedInteger(Title::FIELD_DURATION)->nullable();
            $table->string(Title::FIELD_TYPE);
            $table->string(Title::FIELD_STATUS);
            $table->string(Title::FIELD_POSTER_URL)->nullable();
            $table->string(Title::FIELD_BANNER_URL)->nullable();
            $table->decimal(Title::FIELD_RATING_AVG, 3)->default(0);
            $table->unsignedInteger(Title::FIELD_RATING_COUNT)->default(0);
            $table->text(Title::FIELD_EMBEDDING)->nullable();
            $table->string(Title::FIELD_UPDATED_BY)->default('process');
            $table->timestamps();
        });

        Schema::create(AttributeOption::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->foreignId(AttributeOption::FIELD_ATTRIBUTE_ID)
                ->constrained(AttributeDefinition::TABLE_NAME)
                ->cascadeOnDelete();
            $table->string(AttributeOption::FIELD_VALUE_RU);
            $table->string(AttributeOption::FIELD_VALUE_EN)->nullable();
            $table->timestamps();
            $table->unique([AttributeOption::FIELD_ATTRIBUTE_ID, AttributeOption::FIELD_VALUE_RU]);
        });

        Schema::create(TitleAttribute::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->foreignId(TitleAttribute::FIELD_TITLE_ID)->constrained(Title::TABLE_NAME)->cascadeOnDelete();
            $table->foreignId(TitleAttribute::FIELD_ATTRIBUTE_ID)
                ->constrained(AttributeDefinition::TABLE_NAME)
                ->cascadeOnDelete();
            $table->string(TitleAttribute::FIELD_VALUE_TEXT)->nullable();
            $table->json(TitleAttribute::FIELD_VALUE_ARRAY)->nullable();
            $table->decimal(TitleAttribute::FIELD_VALUE_NUMBER, 15, 4)->nullable();
            $table->boolean(TitleAttribute::FIELD_VALUE_BOOLEAN)->nullable();
            $table->text(TitleAttribute::FIELD_SEARCHABLE_TEXT)->nullable();
            $table->timestamps();
            $table->unique([TitleAttribute::FIELD_TITLE_ID, TitleAttribute::FIELD_ATTRIBUTE_ID]);
        });
    }
}
