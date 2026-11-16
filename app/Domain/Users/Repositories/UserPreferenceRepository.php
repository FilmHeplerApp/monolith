<?php

declare(strict_types=1);

namespace App\Domain\Users\Repositories;

use App\Domain\Users\Entities\UserPreference;
use App\Domain\Users\Exceptions\UserNotFoundException;

interface UserPreferenceRepository
{
    /**
     * @throws UserNotFoundException When the user does not exist.
     */
    public function save(UserPreference $preference): UserPreference;

    public function findByUserId(int $userId): ?UserPreference;
}
