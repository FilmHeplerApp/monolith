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
    public function email_normalizes_and_validates(): void
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
}
