<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\Enums\Title\TitleUpdatedBy;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use Database\Factories\Fixtures\TitleFixture;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

#[UseModel(Title::class)]
class TitleFactory extends Factory
{
    private const string EN_LOCALE = 'en_US';
    private const int EMBEDDING_SIZE = 1535;
    private const int ZERO_VALUE = 0;


    public function definition(): array
    {
        return [
            Title::FIELD_UUID => fake()->uuid(),
            Title::FIELD_CANONICAL_KEY => fake()->unique()->bothify('canonical-????-####'),
            Title::FIELD_TITLE_RU => $this->generateRuTitle(),
            Title::FIELD_TITLE_EN => fake(self::EN_LOCALE)->title,
            Title::FIELD_DESCRIPTION_RU => fake()->randomElement(TitleFixture::RU_DESCRIPTIONS),
            Title::FIELD_DESCRIPTION_EN => fake(self::EN_LOCALE)->paragraphs(3, true),
            Title::FIELD_SHORT_PLOT_RU => fake()->randomElement(TitleFixture::RU_SHORT_PLOTS),
            Title::FIELD_DURATION => fake()->numberBetween(20, 180),
            Title::FIELD_TYPE => fake()->randomElement(TitleContentType::cases()),
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
        $embedding = range(self::ZERO_VALUE, self::EMBEDDING_SIZE);
        shuffle($embedding);
        return $embedding;
    }

    private function generateRuTitle(): string
    {
        return sprintf(
            '%s %s #%d',
            fake()->randomElement(TitleFixture::RU_ADJECTIVES),
            fake()->randomElement(TitleFixture::RU_NOUNS),
            fake()->unique()->numberBetween(1, 999999),
        );
    }
}
