<?php

declare(strict_types=1);

namespace App\Domain\Users\Repositories;

use App\Domain\Users\Entities\User;
use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\Exceptions\UserNotFoundException;
use App\Domain\Users\ValueObjects\User\Email;
use App\Domain\Users\ValueObjects\User\Nickname;

interface UserRepository
{
    public function save(User $user): User;

    public function findById(int $id): ?User;

    public function findByEmail(Email $email): ?User;

    public function findByNickname(Nickname $nickname): ?User;

    /**
     * @throws InvalidUserValueException When the user has not been persisted.
     * @throws UserNotFoundException When the user does not exist.
     */
    public function delete(User $user): void;

    /**
     * Permanently removes the user and the rows owned by the user.
     * A soft-deleted user can still be purged.
     *
     * @throws InvalidUserValueException When the user has not been persisted.
     * @throws UserNotFoundException When the user does not exist.
     */
    public function forceDelete(User $user): void;
}
