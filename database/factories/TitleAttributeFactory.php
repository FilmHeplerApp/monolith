<?php

namespace Database\Factories;

use App\Models\AttributeDefinition;
use App\Models\Title;
use App\Models\TitleAttribute;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

#[UseModel(TitleAttribute::class)]
class TitleAttributeFactory extends Factory
{
    private const array VALUE_ARRAY_VALUES = ['one', 'two', 'three', 'four'];


    public function definition(): array
    {
        return [
            TitleAttribute::FIELD_TITLE_ID => Title::factory(),
            TitleAttribute::FIELD_ATTRIBUTE_ID => AttributeDefinition::factory(),
            TitleAttribute::FIELD_VALUE_TEXT => fake()->optional()->word(),
            TitleAttribute::FIELD_VALUE_ARRAY => fake()->optional()->randomElements(
                self::VALUE_ARRAY_VALUES,
                fake()->numberBetween(1, 3)
            ),
            TitleAttribute::FIELD_VALUE_NUMBER => fake()->optional()->randomFloat(2, 0, 100),
            TitleAttribute::FIELD_VALUE_BOOLEAN => fake()->optional()->boolean(),
            TitleAttribute::FIELD_SEARCHABLE_TEXT => fake()->sentence(),
        ];
    }
}
