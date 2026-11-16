<?php

declare(strict_types=1);

namespace App\Domain\Users\ValueObjects\User;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use DateTimeImmutable;

final readonly class Birthday
{
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
