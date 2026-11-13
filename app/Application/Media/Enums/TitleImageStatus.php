<?php

declare(strict_types=1);

namespace App\Application\Media\Enums;

enum TitleImageStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
