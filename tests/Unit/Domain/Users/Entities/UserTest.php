<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Users\Entities;

use App\Domain\Shared\ValueObjects\Embedding;
use App\Domain\Users\Entities\User;
use App\Domain\Users\Enums\User\UserRole;
use App\Domain\Users\Enums\User\UserStatus;
use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\Services\BirthdayPolicy;
use App\Domain\Users\ValueObjects\User\Birthday;
use App\Domain\Users\ValueObjects\User\Email;
use App\Domain\Users\ValueObjects\User\Nickname;
use App\Domain\Users\ValueObjects\User\PasswordHash;
use App\Domain\Users\ValueObjects\User\SecretPhraseHash;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UserTest extends TestCase
{
    private const string PASSWORD_HASH = '$2y$04$Z2UUGoTmGSItu9967u1JNOEGIZMm4VXC1S1qTBrZIkJ/cv5vHNMuq';
    private const string PHRASE_HASH = 'phrase-hash';


    #[Test]
    public function it_cannot_be_created_through_the_constructor(): void
    {
        self::assertTrue((new ReflectionMethod(User::class, '__construct'))->isPrivate());
    }

    #[Test]
    public function register_creates_a_new_user_with_the_initial_state(): void
    {
        $user = $this->register($this->birthday('1994-03-12'));

        self::assertNull($user->id);
        self::assertSame('anton', $user->nickname->getValue());
        self::assertSame('anton@example.com', $user->email->getValue());
        self::assertSame(self::PASSWORD_HASH, $user->password->getValue());
        self::assertSame(self::PHRASE_HASH, $user->secretPhraseHash?->getValue());
        self::assertSame('1994-03-12', $user->birthday?->getValue()->format('Y-m-d'));
        self::assertNull($user->avatarKey);
        self::assertNull($user->embedding);
        self::assertSame(UserStatus::VALID, $user->status);
        self::assertTrue($user->isActive);
        self::assertSame(UserRole::USER, $user->role);
        self::assertNull($user->lastActiveAt);
        self::assertNull($user->createdAt);
        self::assertNull($user->updatedAt);
        self::assertNull($user->deletedAt);
    }

    #[Test]
    public function register_allows_a_user_without_a_birthday(): void
    {
        self::assertNull($this->register(null)->birthday);
    }

    #[Test]
    public function register_applies_the_birthday_policy(): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessage(sprintf('Birthday must not be earlier than %d.', BirthdayPolicy::MIN_YEAR));

        $this->register($this->birthday(sprintf('%d-12-31', BirthdayPolicy::MIN_YEAR - 1)));
    }

    #[Test]
    public function reconstitute_restores_the_stored_state_without_applying_policies(): void
    {
        $embedding = Embedding::createFromArray(array_fill(0, Embedding::DIMENSION, 0.5));
        $lastActiveAt = new DateTimeImmutable('2026-10-20 18:15:00');
        $createdAt = new DateTimeImmutable('2026-01-01 10:00:00');
        $updatedAt = new DateTimeImmutable('2026-02-01 10:00:00');
        $deletedAt = new DateTimeImmutable('2026-03-01 10:00:00');
        $birthday = $this->birthday(sprintf('%d-05-01', BirthdayPolicy::MIN_YEAR - 10));

        $user = User::reconstitute(
            id: 42,
            nickname: Nickname::createFromString('anton'),
            email: Email::createFromString('anton@example.com'),
            password: PasswordHash::createFromHash(self::PASSWORD_HASH),
            avatarKey: 'users/avatars/avatar.webp',
            embedding: $embedding,
            status: UserStatus::BLOCKED,
            isActive: false,
            role: UserRole::ADMIN,
            lastActiveAt: $lastActiveAt,
            birthday: $birthday,
            secretPhraseHash: null,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            deletedAt: $deletedAt,
        );

        self::assertSame(42, $user->id);
        self::assertSame('users/avatars/avatar.webp', $user->avatarKey);
        self::assertSame($embedding, $user->embedding);
        self::assertSame(UserStatus::BLOCKED, $user->status);
        self::assertFalse($user->isActive);
        self::assertSame(UserRole::ADMIN, $user->role);
        self::assertSame($lastActiveAt, $user->lastActiveAt);
        self::assertSame($birthday, $user->birthday);
        self::assertNull($user->secretPhraseHash);
        self::assertSame($createdAt, $user->createdAt);
        self::assertSame($updatedAt, $user->updatedAt);
        self::assertSame($deletedAt, $user->deletedAt);
    }


    private function register(?Birthday $birthday): User
    {
        return User::register(
            nickname: Nickname::createFromString('anton'),
            email: Email::createFromString('anton@example.com'),
            password: PasswordHash::createFromHash(self::PASSWORD_HASH),
            secretPhraseHash: SecretPhraseHash::createFromHash(self::PHRASE_HASH),
            birthday: $birthday,
            birthdayPolicy: new BirthdayPolicy,
        );
    }

    private function birthday(string $date): Birthday
    {
        return Birthday::createFromDateTime(new DateTimeImmutable($date));
    }
}
