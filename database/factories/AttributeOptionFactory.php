<?php

namespace Database\Factories;

use App\Models\AttributeDefinition;
use App\Models\AttributeOption;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

#[UseModel(AttributeOption::class)]
class AttributeOptionFactory extends Factory
{
    private const string EN_LOCALE = 'en_US';
    private const string RU_LOCALE = 'ru_RU';


    public function definition(): array
    {
        return [
            AttributeOption::FIELD_ATTRIBUTE_ID => AttributeDefinition::factory(),
            AttributeOption::FIELD_VALUE_RU => fake(self::RU_LOCALE)->unique()->word(),
            AttributeOption::FIELD_VALUE_EN => fake(self::EN_LOCALE)->unique()->word(),
        ];
    }
}
