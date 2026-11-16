<?php

namespace App\Domain\Users\Enums\UserPreference;

enum Theme: string
{
    case DARK = 'dark';
    case LIGHT = 'light';
    case SYSTEM = 'system';
}
