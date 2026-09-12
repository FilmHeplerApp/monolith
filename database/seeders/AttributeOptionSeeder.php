<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeDefinition;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttributeOptionSeeder extends Seeder
{
    private const string GENRES = 'genres';
    private const string TARGET_AUDIENCE = 'target_audience';
    private const string TAGS = 'tags';
    private const string STUDIOS = 'studios';
    private const array UNIQUE_BY_TEMPLATE = [AttributeOption::FIELD_ATTRIBUTE_ID, AttributeOption::FIELD_VALUE_RU];
    private const array GENRES_ARRAY = [
        ['Экшен', 'Action'],
        ['Комедия', 'Comedy'],
        ['Драма', 'Drama'],
        ['Романтика', 'Romance'],
        ['Фэнтези', 'Fantasy'],
        ['Повседневность', 'Slice of Life']
    ];
    private const array TARGET_AUDIENCE_ARRAY = [
        ['Кодомо', 'Kodomo'],
        ['Сёнен', 'Shounen'],
        ['Сёдзё', 'Shoujo'],
        ['Сэйнэн', 'Seinen'],
        ['Дзёсэй', 'Josei'],
    ];
    private const array TAGS_ARRAY = [
        ['Исекай', 'Isekai'],
        ['Школа', 'School'],
        ['Магия', 'Magic'],
        ['Сверхъестественное', 'Supernatural'],
        ['Романтика', 'Romance'],
        ['Комедия', 'Comedy'],
        ['Драма', 'Drama'],
        ['Экшен', 'Action'],
        ['Приключения', 'Adventure'],
        ['Фэнтези', 'Fantasy'],
        ['Научная фантастика', 'Sci-Fi'],
        ['Меха', 'Mecha'],
        ['Повседневность', 'Slice of Life'],
        ['Психология', 'Psychological'],
        ['Детектив', 'Mystery'],
        ['Триллер', 'Thriller'],
        ['Ужасы', 'Horror'],
        ['Исторический', 'Historical'],
        ['Спорт', 'Sports'],
        ['Музыка', 'Music'],
        ['Айдолы', 'Idol'],
        ['Военное', 'Military'],
        ['Вампиры', 'Vampires'],
        ['Демоны', 'Demons'],
        ['Путешествия во времени', 'Time Travel'],
    ];
    private const array STUDIOS_ARRAY = [
        ['Kyoto Animation', 'Kyoto Animation'],
        ['MAPPA', 'MAPPA'],
        ['ufotable', 'ufotable'],
        ['WIT Studio', 'WIT Studio'],
        ['Bones', 'Bones'],
        ['Madhouse', 'Madhouse'],
        ['A-1 Pictures', 'A-1 Pictures'],
        ['CloverWorks', 'CloverWorks'],
        ['Pierrot', 'Pierrot'],
        ['Toei Animation', 'Toei Animation'],
        ['Sunrise', 'Sunrise'],
        ['TRIGGER', 'TRIGGER'],
        ['SHAFT', 'SHAFT'],
        ['Production I.G', 'Production I.G'],
        ['J.C.STAFF', 'J.C.STAFF'],
        ['White Fox', 'White Fox'],
        ['Doga Kobo', 'Doga Kobo'],
        ['Lerche', 'Lerche'],
        ['Studio Deen', 'Studio Deen'],
        ['8bit', '8bit'],
    ];


    public function run(): void
    {
        $this->seedGenre();
        $this->seedTargetAudience();
        $this->seedTags();
        $this->seedStudios();
    }


    private function seedGenre(): void
    {
        $attribute = AttributeDefinition::query()
            ->where(AttributeDefinition::FIELD_CODE, self::GENRES)
            ->firstOrFail('id');

        $dataToSave = $this->combineData($attribute->id, self::GENRES_ARRAY);
        if (empty($dataToSave)) {
            return;
        }

        $this->upsertData($dataToSave);
    }

    private function seedTargetAudience(): void
    {
        $attribute = AttributeDefinition::query()
            ->where(AttributeDefinition::FIELD_CODE, self::TARGET_AUDIENCE)
            ->firstOrFail('id');

        $dataToSave = $this->combineData($attribute->id, self::TARGET_AUDIENCE_ARRAY);
        if (empty($dataToSave)) {
            return;
        }

        $this->upsertData($dataToSave);
    }

    private function seedTags(): void
    {
        $attribute = AttributeDefinition::query()
            ->where(AttributeDefinition::FIELD_CODE, self::TAGS)
            ->firstOrFail('id');

        $dataToSave = $this->combineData($attribute->id, self::TAGS_ARRAY);
        if (empty($dataToSave)) {
            return;
        }

        $this->upsertData($dataToSave);
    }

    private function seedStudios(): void
    {
        $attribute = AttributeDefinition::query()
            ->where(AttributeDefinition::FIELD_CODE, self::STUDIOS)
            ->firstOrFail();

        $dataToSave = $this->combineData($attribute->id, self::STUDIOS_ARRAY);
        if (empty($dataToSave)) {
            return;
        }

        $this->upsertData($dataToSave);
    }

    private function combineData(int $attributeId, array $incomingData): array
    {
        if (!$this->isIncomingDataValid($incomingData)) {
            return [];
        }

        $dataToSave = [];
        foreach ($incomingData as [$ru, $en]) {
            $dataToSave[] = [
                AttributeOption::FIELD_ATTRIBUTE_ID => $attributeId,
                AttributeOption::FIELD_VALUE_EN => $en,
                AttributeOption::FIELD_VALUE_RU => $ru,
            ];
        }
        return $dataToSave;
    }

    private function isIncomingDataValid(array $incomingData): bool
    {
        return array_all($incomingData, fn($value, $key) => !is_null($key) && !is_null($value));
    }

    private function upsertData(array $data): void
    {
        DB::table(AttributeOption::TABLE_NAME)->upsert($data, self::UNIQUE_BY_TEMPLATE);
    }
}
