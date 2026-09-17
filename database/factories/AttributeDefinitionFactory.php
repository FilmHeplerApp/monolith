<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeDefinition;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

#[UseModel(AttributeDefinition::class)]
class AttributeDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            AttributeDefinition::FIELD_CONTENT_TYPE_CODE => TitleContentType::ANIME->value,
            AttributeDefinition::FIELD_IS_FILTERABLE => fake()->boolean(80),
            AttributeDefinition::FIELD_IS_REQUIRED => fake()->boolean(40),
            AttributeDefinition::FIELD_ORDER => fake()->numberBetween(1, 100),
        ];
    }

    public function withContentType(): static
    {
        return $this->state(fn() => [
            AttributeDefinition::FIELD_CONTENT_TYPE_CODE => fake()->randomElement(TitleContentType::cases()),
        ]);
    }
}
