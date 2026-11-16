<?php

namespace App\Domain\Users\Enums\User;

enum UserRole: string
{
    case USER = 'user';
    case MODERATOR = 'moderator';
    case ADMIN = 'admin';
}
