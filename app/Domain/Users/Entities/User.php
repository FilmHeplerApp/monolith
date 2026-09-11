<?php

declare(strict_types=1);

namespace App\Domain\Users\Entities;

use App\Domain\Users\ValueObjects\User\Email;
use App\Domain\Users\ValueObjects\User\Name;
use DateTimeImmutable;

final readonly class User
{
    public function __construct(
        public ?int               $id,
        public Name               $name,
        public Email              $email,
        public ?DateTimeImmutable $emailVerifiedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }
}
