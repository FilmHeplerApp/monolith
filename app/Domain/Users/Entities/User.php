<?php

declare(strict_types=1);

namespace App\Domain\Users\Entities;

use App\Domain\Shared\ValueObjects\Embedding;
use App\Domain\Users\Enums\User\UserRole;
use App\Domain\Users\Enums\User\UserStatus;
use App\Domain\Users\ValueObjects\User\Birthday;
use App\Domain\Users\ValueObjects\User\Email;
use App\Domain\Users\ValueObjects\User\Nickname;
use App\Domain\Users\ValueObjects\User\PasswordHash;
use App\Domain\Users\ValueObjects\User\SecretPhraseHash;
use DateTimeImmutable;

final readonly class User
{
    public function __construct(
        public ?int               $id,
        public Nickname           $nickname,
        public Email              $email,
        public PasswordHash       $password,
        public ?string            $avatarKey,
        public ?Embedding         $embedding,
        public UserStatus         $status,
        public bool               $isActive,
        public UserRole           $role,
        public ?DateTimeImmutable $lastActiveAt,
        public Birthday           $birthday,
        public SecretPhraseHash   $secretPhraseHash,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?DateTimeImmutable $deletedAt = null,
    ) {
    }
}
