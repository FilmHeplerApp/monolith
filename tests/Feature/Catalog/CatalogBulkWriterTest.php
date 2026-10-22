<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Catalog\DTOs\AttributeDefinitionData;
use App\Application\Catalog\DTOs\AttributeOptionData;
use App\Application\Catalog\DTOs\PreparedCatalogDto;
use App\Application\Catalog\DTOs\TitleAttributeData;
use App\Application\Catalog\DTOs\TitleData;
use App\Application\Import\DTOs\CatalogAttributePackData;
use App\Application\Import\DTOs\CatalogPackInput;
use App\Application\Import\DTOs\CatalogTitlePackData;
use App\Application\Import\Services\CatalogPacker;
use App\Application\Import\Services\ImportAttributeRegistry;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\Enums\Title\TitleUpdatedBy;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\Duration;
use App\Domain\Catalog\ValueObjects\Title\ReleaseYear;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Domain\Catalog\ValueObjects\Title\TitleUuid;
use App\Domain\Catalog\ValueObjects\TitleAttribute\AttributeValue;
use App\Domain\Import\Services\TitleCandidateEvaluator;
use App\Domain\Import\Services\TitleCanonicalKeyFactory;
use App\Domain\Import\Services\TitleKeyResolver;
use App\Infrastructure\Persistence\Eloquent\Bulk\Catalog\TitleContentTypeMismatchException;
use App\Infrastructure\Persistence\Eloquent\Bulk\Catalog\UnresolvedCatalogReferenceException;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeDefinition;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeOption;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\TitleAttribute;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class CatalogBulkWriterTest extends TestCase
{
    private const string TITLE_UUID = '018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f13';
    private const string SECOND_TITLE_UUID = '018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f14';
    private const string CANONICAL_KEY = 'naruto|anime';
    private const string SECOND_CANONICAL_KEY = 'bleach|anime';
    private const string MOVIE_TITLE_UUID = '018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f15';
    private const string MOVIE_CANONICAL_KEY = 'inception|movie';
    private const string MOVIE_GENRE_RU = 'Фантастика';
    private const string MOVIE_GENRE_EN = 'Science Fiction';
    private const string ATTRIBUTE_GENRES = 'genres';
    private const string ATTRIBUTE_STUDIOS = 'studios';
    private const string TITLE_RU = 'Наруто';
    private const string TITLE_EN = 'Naruto';
    private const string GENRE_ACTION_RU = 'Экшен';
    private const string GENRE_ACTION_EN = 'Action';
    private const string GENRE_DRAMA_RU = 'Драма';
    private const string STUDIO_NAME = 'Pierrot';
    private const int DURATION_MINUTES = 24;
    private const int RELEASE_YEAR = 2002;
    private const int EXPECTED_DEFINITION_COUNT = 2;
    private const int EXPECTED_OPTION_COUNT = 2;
    private const int EXPECTED_TITLE_ATTRIBUTE_COUNT = 2;


    private CatalogBulkWriterContract $writer;


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
            ->where(AttributeDefinition::FIELD_CONTENT_TYPE_CODE, TitleContentType::ANIME->value)
            ->where(AttributeDefinition::FIELD_CODE, self::ATTRIBUTE_GENRES)
            ->firstOrFail();
        $studios = AttributeDefinition::query()
            ->where(AttributeDefinition::FIELD_CONTENT_TYPE_CODE, TitleContentType::ANIME->value)
            ->where(AttributeDefinition::FIELD_CODE, self::ATTRIBUTE_STUDIOS)
            ->firstOrFail();

        $this->assertSame(self::TITLE_RU, $title->{Title::FIELD_TITLE_RU});
        $this->assertSame(self::TITLE_EN, $title->{Title::FIELD_TITLE_EN});
        $this->assertSame(self::DURATION_MINUTES, $title->{Title::FIELD_DURATION});
        $this->assertSame(self::RELEASE_YEAR, $title->{Title::FIELD_RELEASE_YEAR});
        $this->assertSame(self::CANONICAL_KEY, $title->{Title::FIELD_CANONICAL_KEY});
        $this->assertSame(TitleContentType::ANIME, $title->{Title::FIELD_TYPE});
        $this->assertFalse($title->{Title::FIELD_IS_INCOMPLETE});
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
            self::GENRE_ACTION_RU.' '.self::GENRE_DRAMA_RU,
            $genreValues->{TitleAttribute::FIELD_SEARCHABLE_TEXT},
        );

        $studioValue = TitleAttribute::query()
            ->where(TitleAttribute::FIELD_TITLE_ID, $title->{Title::FIELD_ID})
            ->where(TitleAttribute::FIELD_ATTRIBUTE_ID, $studios->{AttributeDefinition::FIELD_ID})
            ->firstOrFail();

        $this->assertSame([self::STUDIO_NAME], $studioValue->{TitleAttribute::FIELD_VALUE_ARRAY});
        $this->assertNull($studioValue->{TitleAttribute::FIELD_VALUE_TEXT});
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
            TitleAttribute::FIELD_SEARCHABLE_TEXT => 'Studio Pierrot',
        ]);
    }

    #[Test]
    public function it_stores_the_same_attribute_code_separately_for_each_content_type(): void
    {
        $this->writer->write($this->preparedCatalog());

        $this->writer->write(new PreparedCatalogDto(
            titles: [$this->createTitleData(
                TitleUuid::createFromString(self::MOVIE_TITLE_UUID),
                'Начало',
                'Inception',
                self::MOVIE_CANONICAL_KEY,
                type: TitleContentType::MOVIE,
            )],
            attributeDefinitions: [
                new AttributeDefinitionData(
                    self::ATTRIBUTE_GENRES,
                    $this->createLocalizedText('Жанры фильмов', 'Movie genres'),
                    TitleContentType::MOVIE,
                    AttributeValueType::ARRAY,
                    true,
                    false,
                    1,
                ),
            ],
            attributeOptions: [
                new AttributeOptionData(
                    self::ATTRIBUTE_GENRES,
                    TitleContentType::MOVIE,
                    $this->createLocalizedText(self::MOVIE_GENRE_RU, self::MOVIE_GENRE_EN),
                ),
            ],
            titleAttributes: [
                new TitleAttributeData(
                    TitleCanonicalKey::createFromString(self::MOVIE_CANONICAL_KEY),
                    self::ATTRIBUTE_GENRES,
                    TitleContentType::MOVIE,
                    AttributeValue::createFromArray([self::MOVIE_GENRE_RU]),
                ),
            ],
        ));

        $this->writer->write(new PreparedCatalogDto(
            attributeDefinitions: [
                new AttributeDefinitionData(
                    self::ATTRIBUTE_GENRES,
                    $this->createLocalizedText('Жанры кино', 'Film genres'),
                    TitleContentType::MOVIE,
                    AttributeValueType::ARRAY,
                    true,
                    false,
                    1,
                ),
            ],
        ));

        $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, 3);
        $this->assertDatabaseCount(Title::TABLE_NAME, 2);

        $animeGenres = AttributeDefinition::query()
            ->where(AttributeDefinition::FIELD_CONTENT_TYPE_CODE, TitleContentType::ANIME->value)
            ->where(AttributeDefinition::FIELD_CODE, self::ATTRIBUTE_GENRES)
            ->firstOrFail();
        $movieGenres = AttributeDefinition::query()
            ->where(AttributeDefinition::FIELD_CONTENT_TYPE_CODE, TitleContentType::MOVIE->value)
            ->where(AttributeDefinition::FIELD_CODE, self::ATTRIBUTE_GENRES)
            ->firstOrFail();

        $this->assertNotSame(
            $animeGenres->{AttributeDefinition::FIELD_ID},
            $movieGenres->{AttributeDefinition::FIELD_ID},
        );
        $this->assertSame('Жанры', $animeGenres->{AttributeDefinition::FIELD_NAME_RU});
        $this->assertSame('Жанры кино', $movieGenres->{AttributeDefinition::FIELD_NAME_RU});

        $this->assertDatabaseHas(AttributeOption::TABLE_NAME, [
            AttributeOption::FIELD_ATTRIBUTE_ID => $animeGenres->{AttributeDefinition::FIELD_ID},
            AttributeOption::FIELD_VALUE_RU => self::GENRE_ACTION_RU,
        ]);
        $this->assertDatabaseHas(AttributeOption::TABLE_NAME, [
            AttributeOption::FIELD_ATTRIBUTE_ID => $movieGenres->{AttributeDefinition::FIELD_ID},
            AttributeOption::FIELD_VALUE_RU => self::MOVIE_GENRE_RU,
            AttributeOption::FIELD_VALUE_EN => self::MOVIE_GENRE_EN,
        ]);

        $movie = Title::query()->where(Title::FIELD_UUID, self::MOVIE_TITLE_UUID)->firstOrFail();

        $this->assertDatabaseHas(TitleAttribute::TABLE_NAME, [
            TitleAttribute::FIELD_TITLE_ID => $movie->{Title::FIELD_ID},
            TitleAttribute::FIELD_ATTRIBUTE_ID => $movieGenres->{AttributeDefinition::FIELD_ID},
        ]);
    }

    #[Test]
    public function it_does_not_overwrite_image_keys_on_upsert(): void
    {
        $this->writer->write($this->preparedCatalog());

        $inserted = Title::query()->where(Title::FIELD_CANONICAL_KEY, self::CANONICAL_KEY)->firstOrFail();

        $this->assertNull($inserted->{Title::FIELD_POSTER_KEY});
        $this->assertNull($inserted->{Title::FIELD_POSTER_THUMB_KEY});
        $this->assertNull($inserted->{Title::FIELD_BANNER_KEY});
        $this->assertNull($inserted->{Title::FIELD_POSTER_SOURCE_HASH});
        $this->assertNull($inserted->{Title::FIELD_BANNER_SOURCE_HASH});

        DB::table(Title::TABLE_NAME)
            ->where(Title::FIELD_CANONICAL_KEY, self::CANONICAL_KEY)
            ->update([
                Title::FIELD_POSTER_KEY => 'titles/posters/uuid/full.webp',
                Title::FIELD_POSTER_THUMB_KEY => 'titles/posters/uuid/thumb.webp',
                Title::FIELD_BANNER_KEY => 'titles/banners/uuid/banner.webp',
                Title::FIELD_POSTER_SOURCE_HASH => str_repeat('a', 64),
                Title::FIELD_BANNER_SOURCE_HASH => str_repeat('b', 64),
            ]);

        $this->writer->write($this->preparedCatalog(titleRu: 'Наруто: обновлённый'));

        $this->assertDatabaseHas(Title::TABLE_NAME, [
            Title::FIELD_CANONICAL_KEY => self::CANONICAL_KEY,
            Title::FIELD_TITLE_RU => 'Наруто: обновлённый',
            Title::FIELD_POSTER_KEY => 'titles/posters/uuid/full.webp',
            Title::FIELD_POSTER_THUMB_KEY => 'titles/posters/uuid/thumb.webp',
            Title::FIELD_BANNER_KEY => 'titles/banners/uuid/banner.webp',
            Title::FIELD_POSTER_SOURCE_HASH => str_repeat('a', 64),
            Title::FIELD_BANNER_SOURCE_HASH => str_repeat('b', 64),
        ]);
    }

    #[Test]
    public function it_resolves_definitions_already_stored_when_dto_definitions_are_empty(): void
    {
        $this->writer->write($this->preparedCatalog());

        $secondUuid = TitleUuid::createFromString(self::SECOND_TITLE_UUID);
        $secondCanonicalKey = TitleCanonicalKey::createFromString(self::SECOND_CANONICAL_KEY);

        $this->writer->write(new PreparedCatalogDto(
            titles: [$this->createTitleData(
                $secondUuid,
                'Блич',
                'Bleach',
                self::SECOND_CANONICAL_KEY,
            )],
            attributeOptions: [
                new AttributeOptionData(
                    self::ATTRIBUTE_GENRES,
                    TitleContentType::ANIME,
                    $this->createLocalizedText('Комедия', 'Comedy'),
                ),
            ],
            titleAttributes: [
                new TitleAttributeData(
                    $secondCanonicalKey,
                    self::ATTRIBUTE_GENRES,
                    TitleContentType::ANIME,
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
                        TitleCanonicalKey::createFromString(self::CANONICAL_KEY),
                        'missing',
                        TitleContentType::ANIME,
                        AttributeValue::createFromText('nope'),
                    ),
                ],
            ));
        } finally {
            $this->assertDatabaseCount(Title::TABLE_NAME, 0);
            $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, 0);
        }
    }

    #[Test]
    public function it_rolls_back_when_title_attribute_content_type_does_not_match_the_title(): void
    {
        $this->writer->write($this->preparedCatalog());

        $this->expectException(TitleContentTypeMismatchException::class);

        try {
            $this->writer->write(new PreparedCatalogDto(
                attributeDefinitions: [
                    new AttributeDefinitionData(
                        self::ATTRIBUTE_GENRES,
                        $this->createLocalizedText('Жанры фильмов', 'Movie genres'),
                        TitleContentType::MOVIE,
                        AttributeValueType::ARRAY,
                        true,
                        false,
                        1,
                    ),
                ],
                attributeOptions: [
                    new AttributeOptionData(
                        self::ATTRIBUTE_GENRES,
                        TitleContentType::MOVIE,
                        $this->createLocalizedText(self::MOVIE_GENRE_RU, self::MOVIE_GENRE_EN),
                    ),
                ],
                titleAttributes: [
                    new TitleAttributeData(
                        TitleCanonicalKey::createFromString(self::CANONICAL_KEY),
                        self::ATTRIBUTE_GENRES,
                        TitleContentType::MOVIE,
                        AttributeValue::createFromArray([self::MOVIE_GENRE_RU]),
                    ),
                ],
            ));
        } finally {
            $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, self::EXPECTED_DEFINITION_COUNT);
            $this->assertDatabaseCount(AttributeOption::TABLE_NAME, self::EXPECTED_OPTION_COUNT);
            $this->assertDatabaseCount(Title::TABLE_NAME, 1);
            $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, self::EXPECTED_TITLE_ATTRIBUTE_COUNT);
            $this->assertDatabaseMissing(AttributeDefinition::TABLE_NAME, [
                AttributeDefinition::FIELD_CONTENT_TYPE_CODE => TitleContentType::MOVIE->value,
            ]);
        }
    }

    #[Test]
    public function it_keeps_the_original_uuid_and_created_at_when_title_is_reimported(): void
    {
        $this->travelTo(now()->subDay());

        $this->writer->write($this->preparedCatalog());

        $createdAt = DB::table(Title::TABLE_NAME)->value(Title::FIELD_CREATED_AT);

        $this->travelBack();

        $this->writer->write(new PreparedCatalogDto(
            titles: [$this->createTitleData(
                TitleUuid::createFromString(self::SECOND_TITLE_UUID),
                'Наруто обновлённый',
            )],
        ));

        $this->assertDatabaseCount(Title::TABLE_NAME, 1);

        $this->assertDatabaseHas(Title::TABLE_NAME, [
            Title::FIELD_CANONICAL_KEY => self::CANONICAL_KEY,
            Title::FIELD_UUID => self::TITLE_UUID,
            Title::FIELD_TITLE_RU => 'Наруто обновлённый',
            Title::FIELD_CREATED_AT => $createdAt,
        ]);

        $this->assertNotSame(
            $createdAt,
            DB::table(Title::TABLE_NAME)->value(Title::FIELD_UPDATED_AT),
        );
    }

    #[Test]
    public function it_clears_the_incomplete_flag_when_title_is_reimported_with_full_data(): void
    {
        $this->writer->write(new PreparedCatalogDto(
            titles: [$this->createTitleData(
                TitleUuid::createFromString(self::TITLE_UUID),
                releaseYear: null,
                isIncomplete: true,
            )],
        ));

        $partial = Title::query()->where(Title::FIELD_CANONICAL_KEY, self::CANONICAL_KEY)->firstOrFail();

        $this->assertNull($partial->{Title::FIELD_RELEASE_YEAR});
        $this->assertTrue($partial->{Title::FIELD_IS_INCOMPLETE});

        $this->writer->write(new PreparedCatalogDto(
            titles: [$this->createTitleData(TitleUuid::createFromString(self::TITLE_UUID))],
        ));

        $this->assertDatabaseCount(Title::TABLE_NAME, 1);

        $completed = Title::query()->where(Title::FIELD_CANONICAL_KEY, self::CANONICAL_KEY)->firstOrFail();

        $this->assertSame(self::RELEASE_YEAR, $completed->{Title::FIELD_RELEASE_YEAR});
        $this->assertFalse($completed->{Title::FIELD_IS_INCOMPLETE});
    }

    #[Test]
    public function it_preserves_omitted_attributes_when_the_content_type_does_not_change(): void
    {
        $this->writer->write($this->preparedCatalog());

        $this->writer->write(new PreparedCatalogDto(
            titles: [$this->createTitleData(TitleUuid::createFromString(self::TITLE_UUID))],
            titleAttributes: [new TitleAttributeData(
                TitleCanonicalKey::createFromString(self::CANONICAL_KEY),
                self::ATTRIBUTE_GENRES,
                TitleContentType::ANIME,
                AttributeValue::createFromArray([self::GENRE_DRAMA_RU]),
            )],
        ));

        $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, 2);
        $this->assertDatabaseHas(TitleAttribute::TABLE_NAME, [TitleAttribute::FIELD_SEARCHABLE_TEXT => self::STUDIO_NAME]);
    }

    #[Test]
    public function it_persists_packed_english_studios_and_reimports_without_duplicates(): void
    {
        $input = new CatalogPackInput(
            titles: [
                $this->packTitle(self::CANONICAL_KEY, [LocalizedText::create(null, 'Sunrise'), LocalizedText::create(null, 'Bones')]),
                $this->packTitle(self::SECOND_CANONICAL_KEY, [$this->createLocalizedText('Sunrise')]),
            ],
            attributeDefinitions: [(new ImportAttributeRegistry)->definition(TitleContentType::ANIME, self::ATTRIBUTE_STUDIOS)],
        );
        $packer = new CatalogPacker;

        $this->writer->write($packer->pack($input));
        $originalIds = DB::table(Title::TABLE_NAME)->orderBy(Title::FIELD_ID)->pluck(Title::FIELD_UUID)->all();
        $this->writer->write($packer->pack($input));

        $this->assertSame($originalIds, DB::table(Title::TABLE_NAME)->orderBy(Title::FIELD_ID)->pluck(Title::FIELD_UUID)->all());
        $this->assertDatabaseCount(Title::TABLE_NAME, 2);
        $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, 1);
        $this->assertDatabaseCount(AttributeOption::TABLE_NAME, 2);
        $this->assertDatabaseCount(TitleAttribute::TABLE_NAME, 2);
        $this->assertDatabaseHas(AttributeOption::TABLE_NAME, [
            AttributeOption::FIELD_VALUE_RU => 'Sunrise',
            AttributeOption::FIELD_VALUE_EN => 'Sunrise',
        ]);
        $title = Title::query()->where(Title::FIELD_CANONICAL_KEY, self::CANONICAL_KEY)->firstOrFail();
        $attribute = $title->attributes()->firstOrFail();
        $this->assertSame(['Sunrise', 'Bones'], $attribute->value_array);
        $this->assertNull($attribute->value_text);
        $this->assertSame('Sunrise Bones', $attribute->searchable_text);
        $this->assertNull($title->duration);
    }

    #[Test]
    public function it_uses_seeded_import_types_when_packing_without_incoming_definitions(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $before = DB::table(AttributeDefinition::TABLE_NAME)->orderBy('id')->pluck('id')->all();
        DB::table(AttributeDefinition::TABLE_NAME)->where('code', self::ATTRIBUTE_STUDIOS)->update([
            AttributeDefinition::FIELD_NAME_RU => 'Ручная подпись',
            AttributeDefinition::FIELD_IS_FILTERABLE => true,
        ]);
        $this->seed(AttributeDefinitionSeeder::class);

        $this->writer->write((new CatalogPacker)->pack(new CatalogPackInput(
            titles: [$this->packTitle(self::CANONICAL_KEY, [
                $this->createLocalizedText('Gainax'),
                $this->createLocalizedText('Tatsunoko Production'),
            ])],
            attributeDefinitions: [],
        )));

        $this->assertSame($before, DB::table(AttributeDefinition::TABLE_NAME)->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, count((new ImportAttributeRegistry)->definitions()));
        $this->assertDatabaseHas(AttributeDefinition::TABLE_NAME, [
            AttributeDefinition::FIELD_CONTENT_TYPE_CODE => TitleContentType::ANIME->value,
            AttributeDefinition::FIELD_CODE => self::ATTRIBUTE_STUDIOS,
            AttributeDefinition::FIELD_VALUE_TYPE => AttributeValueType::ARRAY->value,
            AttributeDefinition::FIELD_NAME_RU => 'Ручная подпись',
            AttributeDefinition::FIELD_IS_FILTERABLE => true,
        ]);
        $this->assertSame(['Gainax', 'Tatsunoko Production'], TitleAttribute::query()->firstOrFail()->value_array);
    }

    #[Test]
    public function it_refuses_to_relabel_legacy_studios_during_seeding_without_a_data_migration(): void
    {
        $this->writer->write(new PreparedCatalogDto(attributeDefinitions: [new AttributeDefinitionData(
            self::ATTRIBUTE_STUDIOS,
            $this->createLocalizedText('Студии', 'Studios'),
            TitleContentType::ANIME,
            AttributeValueType::STRING,
            false,
            false,
            5,
        )]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Run the data migrations before seeding');

        try {
            $this->seed(AttributeDefinitionSeeder::class);
        } finally {
            $this->assertDatabaseCount(AttributeDefinition::TABLE_NAME, 1);
            $this->assertDatabaseHas(AttributeDefinition::TABLE_NAME, [
                AttributeDefinition::FIELD_CODE => self::ATTRIBUTE_STUDIOS,
                AttributeDefinition::FIELD_VALUE_TYPE => AttributeValueType::STRING->value,
            ]);
        }
    }

    #[Test]
    public function it_saves_the_provider_fixture_through_evaluation_resolution_and_packing(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $rawMapper = new ShikimoriTitleMapper;
        $mapper = app(ProviderTitleToCandidateMapper::class);
        $evaluator = app(TitleCandidateEvaluator::class);
        $candidates = [];
        $flagged = [];
        $demographicCandidate = null;

        foreach (file(base_path('static/Fixtures/Shikimori/animes.ndjson')) as $line) {
            $candidate = $mapper->map($rawMapper->map(json_decode($line, true, 512, JSON_THROW_ON_ERROR)));
            $decision = $evaluator->decide($candidate);
            if ($decision->isRejected()) {
                continue;
            }
            $candidates[] = $candidate;
            $flagged[$candidate->externalId] = $decision->isFlagged();
            if ($candidate->externalId === '8687') {
                $demographicCandidate = $candidate;
            }
        }
        self::assertCount(266, $candidates);
        $resolution = app(TitleKeyResolver::class)->resolve($candidates);
        $registry = new ImportAttributeRegistry;
        $titles = [];
        foreach ($resolution->resolved as $entry) {
            $candidate = $entry->candidate;
            $titles[] = new CatalogTitlePackData(
                canonicalKey: $entry->key,
                title: $candidate->title,
                description: $candidate->description,
                releaseYear: $candidate->releaseYear,
                durationMinutes: $candidate->durationMinutes,
                contentType: $candidate->type,
                status: $candidate->status,
                posterUrl: $candidate->posterUrl,
                bannerUrl: $candidate->bannerUrl,
                isIncomplete: $flagged[$candidate->externalId],
                attributes: array_map(fn ($attribute) => new CatalogAttributePackData(
                    $attribute->code, $registry->definition($candidate->type, $attribute->code)->valueType, $attribute->values,
                ), $candidate->attributes),
            );
        }
        $packed = (new CatalogPacker)->pack(new CatalogPackInput($titles, []));
        $this->writer->write($packed);
        $ids = Title::query()->orderBy('id')->pluck('uuid')->all();
        $this->writer->write($packed);
        self::assertSame($ids, Title::query()->orderBy('id')->pluck('uuid')->all());
        $this->assertDatabaseCount(Title::TABLE_NAME, count($resolution->resolved));
        self::assertNotNull($demographicCandidate);
        $key = app(TitleCanonicalKeyFactory::class)->create($demographicCandidate);
        $title = Title::query()->where('canonical_key', $key->getValue())->firstOrFail();
        $definition = AttributeDefinition::query()->where('code', 'target_audience')->firstOrFail();
        self::assertSame(['Детское', 'Сёнен'], $title->attributes()->where('attribute_id', $definition->id)->firstOrFail()->value_array);
    }


    /**
     * @throws BindingResolutionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCatalogSchema();
        $this->writer = $this->app->make(CatalogBulkWriterContract::class);
    }


    /** @param list<LocalizedText> $studios */
    private function packTitle(string $key, array $studios): CatalogTitlePackData
    {
        $definition = (new ImportAttributeRegistry)->definition(TitleContentType::ANIME, self::ATTRIBUTE_STUDIOS);

        return new CatalogTitlePackData(
            canonicalKey: TitleCanonicalKey::createFromString($key),
            title: $this->createLocalizedText(self::TITLE_RU, self::TITLE_EN),
            description: $this->createLocalizedText('Описание', 'Description'),
            releaseYear: self::RELEASE_YEAR,
            durationMinutes: null,
            contentType: TitleContentType::ANIME,
            status: TitleStatus::RELEASED,
            posterUrl: 'https://example.test/poster.jpg',
            bannerUrl: null,
            isIncomplete: false,
            attributes: [new CatalogAttributePackData(self::ATTRIBUTE_STUDIOS, $definition->valueType, $studios)],
        );
    }

    private function preparedCatalog(
        string $titleRu = self::TITLE_RU,
        string $optionValueEn = self::GENRE_ACTION_EN,
        string $studioName = self::STUDIO_NAME,
        string $studioOptionEn = self::STUDIO_NAME,
    ): PreparedCatalogDto {
        $uuid = TitleUuid::createFromString(self::TITLE_UUID);
        $canonicalKey = TitleCanonicalKey::createFromString(self::CANONICAL_KEY);

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
                    AttributeValueType::ARRAY,
                    false,
                    false,
                    2,
                ),
            ],
            attributeOptions: [
                new AttributeOptionData(
                    self::ATTRIBUTE_GENRES,
                    TitleContentType::ANIME,
                    $this->createLocalizedText(self::GENRE_ACTION_RU, $optionValueEn),
                ),
                new AttributeOptionData(
                    self::ATTRIBUTE_STUDIOS,
                    TitleContentType::ANIME,
                    $this->createLocalizedText(self::STUDIO_NAME, $studioOptionEn),
                ),
            ],
            titleAttributes: [
                new TitleAttributeData(
                    $canonicalKey,
                    self::ATTRIBUTE_GENRES,
                    TitleContentType::ANIME,
                    AttributeValue::createFromArray([self::GENRE_ACTION_RU, self::GENRE_DRAMA_RU]),
                ),
                new TitleAttributeData(
                    $canonicalKey,
                    self::ATTRIBUTE_STUDIOS,
                    TitleContentType::ANIME,
                    AttributeValue::createFromArray([$studioName]),
                ),
            ],
        );
    }

    private function createTitleData(
        TitleUuid        $uuid,
        string           $titleRu = self::TITLE_RU,
        string           $titleEn = self::TITLE_EN,
        string           $canonicalKey = self::CANONICAL_KEY,
        ?int             $releaseYear = self::RELEASE_YEAR,
        bool             $isIncomplete = false,
        TitleContentType $type = TitleContentType::ANIME,
    ): TitleData {
        return new TitleData(
            uuid: $uuid,
            canonicalKey: TitleCanonicalKey::createFromString($canonicalKey),
            title: $this->createLocalizedText($titleRu, $titleEn),
            description: $this->createLocalizedText('Описание', 'Description'),
            shortPlotRu: 'Короткий сюжет',
            duration: Duration::createFromMinutes(self::DURATION_MINUTES),
            releaseYear: ReleaseYear::createFromYear($releaseYear),
            type: $type,
            status: TitleStatus::RELEASED,
            posterKey: null,
            posterThumbKey: null,
            bannerKey: null,
            posterSourceHash: null,
            bannerSourceHash: null,
            isIncomplete: $isIncomplete,
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
            $table->string(AttributeDefinition::FIELD_CODE);
            $table->unique([
                AttributeDefinition::FIELD_CONTENT_TYPE_CODE,
                AttributeDefinition::FIELD_CODE,
            ]);
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
            $table->unsignedSmallInteger(Title::FIELD_RELEASE_YEAR)->nullable();
            $table->string(Title::FIELD_TYPE);
            $table->string(Title::FIELD_STATUS);
            $table->string(Title::FIELD_POSTER_KEY)->nullable();
            $table->string(Title::FIELD_POSTER_THUMB_KEY)->nullable();
            $table->string(Title::FIELD_BANNER_KEY)->nullable();
            $table->char(Title::FIELD_POSTER_SOURCE_HASH, 64)->nullable();
            $table->char(Title::FIELD_BANNER_SOURCE_HASH, 64)->nullable();
            $table->decimal(Title::FIELD_RATING_AVG, 3)->default(0);
            $table->unsignedInteger(Title::FIELD_RATING_COUNT)->default(0);
            $table->text(Title::FIELD_EMBEDDING)->nullable();
            $table->boolean(Title::FIELD_IS_INCOMPLETE)->default(false);
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
