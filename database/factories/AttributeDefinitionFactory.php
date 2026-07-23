<?php

namespace Database\Factories;

use App\Enums\Title\ContentType;
use App\Models\AttributeDefinition;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

#[UseModel(AttributeDefinition::class)]
class AttributeDefinitionFactory extends Factory
{
    private const array CODES_ARRAY = [
        'genres' => 'array',
        'moods' => 'array',
        'tags' => 'array',
        'target_audience' => 'string',
        'studios' => 'string',
        'pace' => 'string',
    ];


    public function definition(): array
    {
        $code = fake()->randomElement(array_keys(self::CODES_ARRAY));

        return [
            AttributeDefinition::FIELD_CONTENT_TYPE_CODE => ContentType::ANIME->value,
            AttributeDefinition::FIELD_CODE => $code,
            AttributeDefinition::FIELD_TYPE => self::CODES_ARRAY[$code],
            AttributeDefinition::FIELD_IS_FILTERABLE => fake()->boolean(80),
            AttributeDefinition::FIELD_IS_REQUIRED => fake()->boolean(40),
            AttributeDefinition::FIELD_ORDER => fake()->numberBetween(1, 100),
        ];
    }

    public function withContentType(): static
    {
        return $this->state(fn() => [
            AttributeDefinition::FIELD_CONTENT_TYPE_CODE => fake()->randomElement(ContentType::cases()),
        ]);
    }
}
