<?php

namespace App\Domain\Users\Enums\User;

enum UserStatus: string
{
    case VALID = 'valid';
    case BLOCKED = 'blocked';
}
