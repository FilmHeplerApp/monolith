<?php

namespace App\Domain\Catalog\Enums\Title;

enum TitleStatus: string
{
    case ANNOUNCED = 'announced';
    case ONGOING = 'ongoing';
    case RELEASED = 'released';
}
