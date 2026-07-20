<?php

namespace App\Enums\Title;

enum AttributeType: string
{
    case STRING = 'string';
    case ARRAY = 'array';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case ENUM = 'enum';
}
