<?php

declare(strict_types=1);

namespace App\Application\Import\Enums;

enum ProviderDataSource: string
{
    case Api = 'api';
    case Dump = 'dump';
}
