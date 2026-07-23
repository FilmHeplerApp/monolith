<?php

namespace Database\Seeders;

use App\Enums\Title\AttributeType;
use App\Models\AttributeDefinition;
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
    private const array TYPE_BY_CODE = [
        'genres' => AttributeType::ARRAY,
        'moods' => AttributeType::ARRAY,
        'tags' => AttributeType::ARRAY,
        'target_audience' => AttributeType::STRING,
        'studios' => AttributeType::STRING,
        'pace' => AttributeType::STRING,
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
                    AttributeDefinition::FIELD_TYPE => self::TYPE_BY_CODE[self::ATTRIBUTE_DEFINITION_CODES[$sequence->index]],
                ]
            ))
            ->create();
    }
}
