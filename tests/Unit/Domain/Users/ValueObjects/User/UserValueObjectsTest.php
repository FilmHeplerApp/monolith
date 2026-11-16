<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Users\ValueObjects\User;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\ValueObjects\User\Birthday;
use App\Domain\Users\ValueObjects\User\Nickname;
use App\Domain\Users\ValueObjects\User\PasswordHash;
use App\Domain\Users\ValueObjects\User\SecretPhraseHash;
use App\Domain\Users\ValueObjects\UserPreference\Timezone;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserValueObjectsTest extends TestCase
{
    private const string BCRYPT_HASH = '$2y$04$Z2UUGoTmGSItu9967u1JNOEGIZMm4VXC1S1qTBrZIkJ/cv5vHNMuq';


    #[Test]
    public function password_and_secret_phrase_hashes_keep_the_given_value(): void
    {
        $password = PasswordHash::createFromHash(self::BCRYPT_HASH);
        $phrase = SecretPhraseHash::createFromHash('phrase-hash');

        $this->assertSame(self::BCRYPT_HASH, $password->getValue());
        $this->assertSame('phrase-hash', $phrase->getValue());
    }

    #[Test]
    public function password_hash_accepts_an_argon2id_hash(): void
    {
        $hash = password_hash('secret', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);

        $this->assertSame($hash, PasswordHash::createFromHash($hash)->getValue());
    }

    #[Test]
    public function password_hash_rejects_a_blank_value(): void
    {
        $this->expectException(InvalidUserValueException::class);

        PasswordHash::createFromHash('   ');
    }

    #[Test]
    #[DataProvider('notPasswordHashes')]
    public function password_hash_rejects_a_value_that_is_not_a_password_hash(string $value): void
    {
        try {
            PasswordHash::createFromHash($value);
            $this->fail('A non-hash value must not be accepted as a password hash.');
        } catch (InvalidUserValueException $exception) {
            $this->assertSame('Password must be stored as a hash produced by password_hash().', $exception->getMessage());
            $this->assertStringNotContainsString($value, $exception->getMessage());
        }
    }

    public static function notPasswordHashes(): iterable
    {
        yield 'plain password' => ['my-secret-password'];
        yield 'md5' => [md5('my-secret-password')];
        yield 'truncated bcrypt' => [substr(self::BCRYPT_HASH, 0, 40)];
    }

    #[Test]
    public function secret_phrase_hash_rejects_a_blank_value(): void
    {
        $this->expectException(InvalidUserValueException::class);

        SecretPhraseHash::createFromHash('   ');
    }

    #[Test]
    public function nickname_rejects_a_blank_value(): void
    {
        $this->expectException(InvalidUserValueException::class);

        Nickname::createFromString('   ');
    }

    #[Test]
    public function birthday_normalizes_to_a_calendar_date(): void
    {
        $birthday = Birthday::createFromDateTime(new DateTimeImmutable('1994-03-12 18:45:00'));

        $this->assertSame('1994-03-12', $birthday->getValue()->format('Y-m-d'));
    }

    #[Test]
    public function birthday_rejects_a_future_date(): void
    {
        $this->expectException(InvalidUserValueException::class);

        Birthday::createFromDateTime(new DateTimeImmutable('tomorrow'));
    }

    #[Test]
    public function birthday_does_not_apply_registration_limits(): void
    {
        $birthday = Birthday::createFromDateTime(new DateTimeImmutable('1899-12-31'));

        $this->assertSame('1899-12-31', $birthday->getValue()->format('Y-m-d'));
    }

    #[Test]
    public function birthday_accepts_today(): void
    {
        $today = new DateTimeImmutable('today');

        $birthday = Birthday::createFromDateTime($today);

        $this->assertSame($today->format('Y-m-d'), $birthday->getValue()->format('Y-m-d'));
    }

    #[Test]
    public function timezone_normalizes_the_default_zone(): void
    {
        $timezone = Timezone::createFromString('  '.Timezone::DEFAULT.'  ');

        $this->assertSame('Europe/Moscow', $timezone->getValue());
        $this->assertSame('Europe/Moscow', (string) $timezone);
    }

    #[Test]
    public function timezone_rejects_an_unknown_zone(): void
    {
        $this->expectException(InvalidUserValueException::class);

        Timezone::createFromString('Not/AZone');
    }
}
