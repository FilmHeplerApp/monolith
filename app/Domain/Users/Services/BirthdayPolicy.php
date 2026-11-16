<?php

declare(strict_types=1);

namespace App\Domain\Users\Services;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\ValueObjects\User\Birthday;
use DateTimeImmutable;

final readonly class BirthdayPolicy
{
    public const int MIN_YEAR = 1950;


    public function assertAllowed(Birthday $birthday): void
    {
        $day = $birthday->getValue();

        if ($day->format('Y-m-d') > new DateTimeImmutable('today')->format('Y-m-d')) {
            throw InvalidUserValueException::birthdayInFuture();
        }

        if ((int) $day->format('Y') < self::MIN_YEAR) {
            throw InvalidUserValueException::birthdayTooEarly(self::MIN_YEAR);
        }
    }
}
