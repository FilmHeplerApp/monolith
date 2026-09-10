<?php

namespace Database\Seeders;

use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Infrastructure\Persistence\Eloquent\Models\AttributeDefinition;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;

class AttributeDefinitionSeeder extends Seeder
{
    private const array ATTRIBUTE_DEFINITION_CODES = [
        'genres',
        'moods',
        'tags',
        'target_audience',
        'studios',
        'pace',
    ];
    private const array VALUE_TYPE_BY_CODE = [
        'genres' => AttributeValueType::ARRAY,
        'moods' => AttributeValueType::ARRAY,
        'tags' => AttributeValueType::ARRAY,
        'target_audience' => AttributeValueType::STRING,
        'studios' => AttributeValueType::STRING,
        'pace' => AttributeValueType::STRING,
    ];


    public function run(): void
    {
        AttributeDefinition::factory()
            ->count(count(self::ATTRIBUTE_DEFINITION_CODES))
            ->state(new Sequence(
                fn(Sequence $sequence) => [
                    AttributeDefinition::FIELD_CODE => self::ATTRIBUTE_DEFINITION_CODES[$sequence->index],
                    AttributeDefinition::FIELD_NAME_RU => __('titles.' . self::ATTRIBUTE_DEFINITION_CODES[$sequence->index]),
                    AttributeDefinition::FIELD_NAME_EN => self::ATTRIBUTE_DEFINITION_CODES[$sequence->index],
                    AttributeDefinition::FIELD_VALUE_TYPE => self::VALUE_TYPE_BY_CODE[self::ATTRIBUTE_DEFINITION_CODES[$sequence->index]],
                ]
            ))
            ->create();
    }
}
