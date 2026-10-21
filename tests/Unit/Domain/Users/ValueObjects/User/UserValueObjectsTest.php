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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserValueObjectsTest extends TestCase
{
    #[Test]
    public function password_and_secret_phrase_hashes_keep_the_given_value(): void
    {
        $password = PasswordHash::createFromHash('password-hash');
        $phrase = SecretPhraseHash::createFromHash('phrase-hash');

        $this->assertSame('password-hash', $password->getValue());
        $this->assertSame('phrase-hash', $phrase->getValue());
    }

    #[Test]
    public function password_hash_rejects_a_blank_value(): void
    {
        $this->expectException(InvalidUserValueException::class);

        PasswordHash::createFromHash('   ');
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
    public function birthday_rejects_a_date_before_the_minimum_year(): void
    {
        $this->expectException(InvalidUserValueException::class);

        Birthday::createFromDateTime(new DateTimeImmutable('1899-12-31'));
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
