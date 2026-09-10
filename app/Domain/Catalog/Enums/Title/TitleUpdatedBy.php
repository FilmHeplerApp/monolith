<?php

namespace App\Domain\Catalog\Enums\Title;

enum TitleUpdatedBy: string
{
    case ADMIN = 'admin';
    case PROCESS = 'process';
}
