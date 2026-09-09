<?php

namespace App\Domain\Catalog\Enums\AttributeDefinition;

enum AttributeValueType: string
{
    case STRING = 'string';
    case ARRAY = 'array';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case ENUM = 'enum';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type) => $type->value,
            self::cases(),
        );
    }
}
