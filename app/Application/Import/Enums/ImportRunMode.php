<?php

declare(strict_types=1);

namespace App\Application\Import\Enums;

enum ImportRunMode: string
{
    case New = 'new';
    case Resumed = 'resumed';
    case AlreadyCompleted = 'alreadyCompleted';
}
