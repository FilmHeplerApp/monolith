<?php

declare(strict_types=1);

namespace App\Domain\Users\DTOs\User;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\ValueObjects\User\Email;
use DateTimeImmutable;

final readonly class UserDto
{
    public function __construct(
        public ?int               $id,
        public string             $name,
        public Email              $email,
        public ?DateTimeImmutable $emailVerifiedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        if (trim($name) === '') {
            throw InvalidUserValueException::blankName();
        }
    }
}
