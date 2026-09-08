<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\Enums\Title\TitleType;
use App\Domain\Catalog\Enums\Title\TitleUpdatedBy;
use App\Infrastructure\Persistence\Eloquent\Models\Title;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

#[UseModel(Title::class)]
class TitleFactory extends Factory
{
    private const string EN_LOCALE = 'en_US';
    private const int EMBEDDING_SIZE = 1535;
    private const int ZERO_VALUE = 0;
    private const array RU_DESCRIPTIONS = [
        'Главный герой неожиданно оказывается втянут в череду загадочных событий, которые полностью меняют его жизнь. Вместе с новыми союзниками ему предстоит преодолеть множество испытаний, раскрыть древние тайны и сделать непростой выбор между долгом и собственными желаниями.',

        'В мире, где магия и технологии существуют бок о бок, обычный школьник получает силу, способную изменить судьбу целого государства. Теперь ему предстоит разобраться в своих способностях, найти настоящих друзей и противостоять могущественным врагам.',

        'После трагических событий прошлого группа молодых людей отправляется в опасное путешествие, чтобы раскрыть секрет древней легенды. На их пути встретятся могущественные противники, неожиданные открытия и испытания, которые навсегда изменят их судьбы.',

        'История рассказывает о повседневной жизни героев, каждый из которых пытается найти своё место в мире. Несмотря на обычные проблемы, их дружба, мечты и переживания постепенно складываются в трогательную и вдохновляющую историю.',

        'Человечество оказалось на грани вымирания после появления неизвестной угрозы. Последняя надежда мира — небольшая команда бойцов, которым предстоит раскрыть происхождение врага и остановить надвигающуюся катастрофу.',

        'Юный талант неожиданно получает шанс исполнить свою мечту. Однако путь к успеху оказывается намного сложнее, чем он предполагал, и требует не только упорства, но и способности доверять окружающим.',

        'Древний артефакт пробуждает силы, существование которых долгое время считалось лишь легендой. Героям предстоит объединиться, чтобы предотвратить надвигающуюся катастрофу и защитить тех, кто им дорог.',

        'После случайной встречи судьбы совершенно разных людей переплетаются самым неожиданным образом. Каждый новый день приносит испытания, открытия и возможность изменить своё будущее.',
    ];
    private const array RU_SHORT_PLOTS = [
        'Обычный школьник получает невероятную силу и оказывается в центре мирового конфликта.',
        'Компания друзей отправляется в опасное путешествие ради спасения близких.',
        'Таинственная находка полностью меняет жизнь главного героя.',
        'Молодому магу предстоит раскрыть древнюю тайну своего рода.',
        'Команда исследователей сталкивается с неизвестной угрозой.',
        'Два непримиримых соперника вынуждены объединиться ради общей цели.',
        'История о дружбе, взрослении и поиске собственного пути.',
        'Последняя надежда человечества оказывается в руках неожиданного героя.',
        'Юная девушка открывает в себе способности, способные изменить мир.',
        'Случайная встреча становится началом невероятного приключения.',
    ];
    private const array RU_ADJECTIVES = [
        'Последний',
        'Древний',
        'Стальной',
        'Алый',
        'Лунный',
        'Тёмный',
        'Забытый',
        'Вечный',
        'Пылающий',
        'Скрытый',
        'Северный',
        'Бесконечный',
        'Проклятый',
        'Небесный',
        'Легендарный',
    ];

    private const array RU_NOUNS = [
        'Хранитель',
        'Воин',
        'Странник',
        'Клинок',
        'Город',
        'Лес',
        'Путь',
        'Горизонт',
        'Император',
        'Дракон',
        'Феникс',
        'Охотник',
        'Маг',
        'Призрак',
        'Рыцарь',
    ];


    public function definition(): array
    {
        return [
            Title::FIELD_EXTERNAL_ID => fake()->uuid(),
            Title::FIELD_TITLE_RU => $this->generateRuTitle(),
            Title::FIELD_TITLE_EN => fake(self::EN_LOCALE)->title,
            Title::FIELD_DESCRIPTION_RU => fake()->randomElement(self::RU_DESCRIPTIONS),
            Title::FIELD_DESCRIPTION_EN => fake(self::EN_LOCALE)->paragraphs(3, true),
            Title::FIELD_SHORT_PLOT_RU => fake()->randomElement(self::RU_SHORT_PLOTS),
            Title::FIELD_DURATION => fake()->numberBetween(20, 180),
            Title::FIELD_TYPE => fake()->randomElement(TitleType::cases()),
            Title::FIELD_STATUS => fake()->randomElement(TitleStatus::cases()),
            Title::FIELD_POSTER_URL => fake()->imageUrl(),
            Title::FIELD_BANNER_URL => fake()->imageUrl(1920, 1080),
            Title::FIELD_RATING_AVG => fake()->randomFloat(2, 0, 9),
            Title::FIELD_RATING_COUNT => fake()->numberBetween(0, 50000),
            Title::FIELD_EMBEDDING => $this->generateEmbedding(),
            Title::FIELD_UPDATED_BY => fake()->randomElement(TitleUpdatedBy::cases()),
        ];
    }

    private function generateEmbedding(): array
    {
        $array =range(self::ZERO_VALUE, self::EMBEDDING_SIZE);
        shuffle($array);
        return $array;
    }

    private function generateRuTitle(): string
    {
        return sprintf(
            '%s %s #%d',
            fake()->randomElement(self::RU_ADJECTIVES),
            fake()->randomElement(self::RU_NOUNS),
            fake()->unique()->numberBetween(1, 999999)
        );
    }
}
