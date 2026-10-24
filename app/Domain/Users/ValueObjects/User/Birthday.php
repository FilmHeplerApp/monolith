<?php

declare(strict_types=1);

namespace App\Domain\Users\ValueObjects\User;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use DateTimeImmutable;

final readonly class Birthday
{
    public const int MIN_YEAR = 1950;


    public static function createFromDateTime(DateTimeImmutable $birthday): self
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $birthday->format('Y-m-d'));

        if (!$day instanceof DateTimeImmutable) {
            throw InvalidUserValueException::invalidBirthday();
        }

        $today = new DateTimeImmutable('today');

        if ($day->format('Y-m-d') > $today->format('Y-m-d')) {
            throw InvalidUserValueException::birthdayInFuture();
        }

        if ((int)$day->format('Y') < self::MIN_YEAR) {
            throw InvalidUserValueException::birthdayTooEarly(self::MIN_YEAR);
        }

        return new self($day);
    }

    public function getValue(): DateTimeImmutable
    {
        return $this->value;
    }


    private function __construct(
        private DateTimeImmutable $value,
    ) {
    }
}
