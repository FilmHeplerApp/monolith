<?php

namespace App\Enums\Title;

enum TitleStatus: string
{
    case ANNOUNCED = 'announced';
    case ONGOING = 'ongoing';
    case RELEASED = 'released';
}
