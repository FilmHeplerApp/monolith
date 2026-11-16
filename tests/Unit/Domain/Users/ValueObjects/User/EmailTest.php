<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Users\ValueObjects\User;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\ValueObjects\User\Email;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    #[Test]
    public function email_normalizes_the_value(): void
    {
        $email = Email::createFromString('  User@Example.COM ');

        $this->assertSame('user@example.com', $email->getValue());
    }

    #[Test]
    public function email_rejects_invalid_value(): void
    {
        $this->expectException(InvalidUserValueException::class);

        Email::createFromString('not-an-email');
    }

    #[Test]
    public function email_accepts_the_maximum_length(): void
    {
        $email = $this->emailOfLength(Email::MAX_LENGTH);

        $this->assertSame($email, Email::createFromString($email)->getValue());
    }

    #[Test]
    public function email_rejects_a_value_longer_than_the_maximum_length(): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessage(sprintf('Email must not be longer than %d characters.', Email::MAX_LENGTH));

        Email::createFromString($this->emailOfLength(Email::MAX_LENGTH + 1));
    }


    private function emailOfLength(int $length): string
    {
        $prefix = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.';
        $suffix = '.com';

        return $prefix.str_repeat('d', $length - strlen($prefix) - strlen($suffix)).$suffix;
    }
}
