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
    #[DataProvider('nicknamesOfMaximumLength')]
    public function nickname_accepts_the_maximum_length_in_characters(string $nickname): void
    {
        $this->assertSame($nickname, Nickname::createFromString($nickname)->getValue());
    }

    public static function nicknamesOfMaximumLength(): iterable
    {
        yield 'latin' => [str_repeat('a', Nickname::MAX_LENGTH)];
        yield 'cyrillic' => [str_repeat('ж', Nickname::MAX_LENGTH)];
    }

    #[Test]
    public function nickname_rejects_a_value_longer_than_the_maximum_length(): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessage(sprintf('Nickname must not be longer than %d characters.', Nickname::MAX_LENGTH));

        Nickname::createFromString(str_repeat('ж', Nickname::MAX_LENGTH + 1));
    }

    #[Test]
    public function nickname_measures_the_length_after_trimming(): void
    {
        $nickname = '  '.str_repeat('a', Nickname::MAX_LENGTH).'  ';

        $this->assertSame(str_repeat('a', Nickname::MAX_LENGTH), Nickname::createFromString($nickname)->getValue());
    }

    #[Test]
    public function birthday_normalizes_to_a_calendar_date(): void
    {
        $birthday = Birthday::createFromDateTime(new DateTimeImmutable('1994-03-12 18:45:00'));

        $this->assertSame('1994-03-12', $birthday->getValue()->format('Y-m-d'));
    }

    #[Test]
    #[DataProvider('datesOutsideTheRegistrationPolicy')]
    public function birthday_does_not_depend_on_the_clock_or_registration_limits(string $date): void
    {
        $birthday = Birthday::createFromDateTime(new DateTimeImmutable($date));

        $this->assertSame((new DateTimeImmutable($date))->format('Y-m-d'), $birthday->getValue()->format('Y-m-d'));
    }

    public static function datesOutsideTheRegistrationPolicy(): iterable
    {
        yield 'before the minimum year' => ['1899-12-31'];
        yield 'tomorrow' => ['tomorrow'];
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
    #[DataProvider('ianaTimezones')]
    public function timezone_accepts_a_current_or_legacy_iana_name(string $value): void
    {
        $this->assertSame($value, Timezone::createFromString($value)->getValue());
    }

    public static function ianaTimezones(): iterable
    {
        yield 'UTC' => ['UTC'];
        yield 'Europe/Kaliningrad' => ['Europe/Kaliningrad'];
        yield 'America/Argentina/Buenos_Aires' => ['America/Argentina/Buenos_Aires'];
        yield 'renamed zone' => ['Europe/Kiev'];
        yield 'legacy alias' => ['US/Eastern'];
    }

    #[Test]
    #[DataProvider('notIanaTimezones')]
    public function timezone_rejects_a_value_that_is_not_an_iana_identifier(string $value): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessage(sprintf('Timezone "%s" is invalid.', $value));

        Timezone::createFromString($value);
    }

    public static function notIanaTimezones(): iterable
    {
        yield 'unknown zone' => ['Not/AZone'];
        yield 'offset' => ['+03:00'];
        yield 'ambiguous abbreviation' => ['CST'];
        yield 'wrong case' => ['europe/moscow'];
    }
}
