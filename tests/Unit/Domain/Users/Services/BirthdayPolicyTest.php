<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Users\Services;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\Services\BirthdayPolicy;
use App\Domain\Users\ValueObjects\User\Birthday;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BirthdayPolicyTest extends TestCase
{
    #[Test]
    #[DataProvider('allowedBirthdays')]
    public function it_allows_a_birthday_from_the_minimum_year(string $date): void
    {
        (new BirthdayPolicy)->assertAllowed($this->birthday($date));

        $this->addToAssertionCount(1);
    }

    public static function allowedBirthdays(): iterable
    {
        yield 'first day of the minimum year' => [sprintf('%d-01-01', BirthdayPolicy::MIN_YEAR)];
        yield 'regular date' => ['1994-03-12'];
        yield 'today' => [(new DateTimeImmutable('today'))->format('Y-m-d')];
    }

    #[Test]
    public function it_rejects_a_birthday_in_the_future(): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessage('Birthday must not be in the future.');

        (new BirthdayPolicy)->assertAllowed($this->birthday('tomorrow'));
    }

    #[Test]
    public function it_rejects_a_birthday_before_the_minimum_year(): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessage(sprintf('Birthday must not be earlier than %d.', BirthdayPolicy::MIN_YEAR));

        (new BirthdayPolicy)->assertAllowed(
            $this->birthday(sprintf('%d-12-31', BirthdayPolicy::MIN_YEAR - 1)),
        );
    }


    private function birthday(string $date): Birthday
    {
        return Birthday::createFromDateTime(new DateTimeImmutable($date));
    }
}
