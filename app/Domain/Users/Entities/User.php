<?php

declare(strict_types=1);

namespace App\Domain\Users\Entities;

use App\Domain\Shared\ValueObjects\Embedding;
use App\Domain\Users\Enums\User\UserRole;
use App\Domain\Users\Enums\User\UserStatus;
use App\Domain\Users\Services\BirthdayPolicy;
use App\Domain\Users\ValueObjects\User\Birthday;
use App\Domain\Users\ValueObjects\User\Email;
use App\Domain\Users\ValueObjects\User\Nickname;
use App\Domain\Users\ValueObjects\User\PasswordHash;
use App\Domain\Users\ValueObjects\User\SecretPhraseHash;
use DateTimeImmutable;

final readonly class User
{
    public static function register(
        Nickname         $nickname,
        Email            $email,
        PasswordHash     $password,
        SecretPhraseHash $secretPhraseHash,
        ?Birthday        $birthday,
        BirthdayPolicy   $birthdayPolicy,
    ): self {
        if ($birthday !== null) {
            $birthdayPolicy->assertAllowed($birthday);
        }

        return new self(
            id: null,
            version: null,
            nickname: $nickname,
            email: $email,
            password: $password,
            avatarKey: null,
            embedding: null,
            status: UserStatus::VALID,
            isActive: true,
            role: UserRole::USER,
            lastActiveAt: null,
            birthday: $birthday,
            secretPhraseHash: $secretPhraseHash,
        );
    }

    /**
     * Restores a stored user. Value objects still check their invariants,
     * but business policies are not applied: the stored state passed them when it was written.
     *
     * @internal Only persistence repositories may call this method.
     */
    public static function reconstitute(
        int                $id,
        int                $version,
        Nickname           $nickname,
        Email              $email,
        PasswordHash       $password,
        ?string            $avatarKey,
        ?Embedding         $embedding,
        UserStatus         $status,
        bool               $isActive,
        UserRole           $role,
        ?DateTimeImmutable $lastActiveAt,
        ?Birthday          $birthday,
        ?SecretPhraseHash  $secretPhraseHash,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
        ?DateTimeImmutable $deletedAt = null,
    ): self {
        return new self(
            id: $id,
            version: $version,
            nickname: $nickname,
            email: $email,
            password: $password,
            avatarKey: $avatarKey,
            embedding: $embedding,
            status: $status,
            isActive: $isActive,
            role: $role,
            lastActiveAt: $lastActiveAt,
            birthday: $birthday,
            secretPhraseHash: $secretPhraseHash,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            deletedAt: $deletedAt,
        );
    }

    public function changeNickname(Nickname $nickname): self
    {
        return new self(...[...get_object_vars($this), 'nickname' => $nickname]);
    }

    public function changeAvatar(?string $avatarKey): self
    {
        return new self(...[...get_object_vars($this), 'avatarKey' => $avatarKey]);
    }

    public function updateEmbedding(Embedding $embedding): self
    {
        return new self(...[...get_object_vars($this), 'embedding' => $embedding]);
    }


    private function __construct(
        public ?int               $id,
        public ?int               $version,
        public Nickname           $nickname,
        public Email              $email,
        public PasswordHash       $password,
        public ?string            $avatarKey,
        public ?Embedding         $embedding,
        public UserStatus         $status,
        public bool               $isActive,
        public UserRole           $role,
        public ?DateTimeImmutable $lastActiveAt,
        public ?Birthday          $birthday,
        public ?SecretPhraseHash  $secretPhraseHash,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?DateTimeImmutable $deletedAt = null,
    ) {}
}
