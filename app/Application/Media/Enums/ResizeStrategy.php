<?php

declare(strict_types=1);

namespace App\Application\Media\Enums;

enum ResizeStrategy: string
{
    case Fit = 'fit';
    case Cover = 'cover';
}
