<?php

declare(strict_types=1);

namespace App\Domain\Users\Services;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\ValueObjects\User\Birthday;

/**
 * Business limits for a birthday entered by the user.
 * Applied only when the value is entered or changed, never when a stored user is restored:
 * the stored value already passed the policy that was in force when it was written.
 */
final readonly class BirthdayPolicy
{
    public const int MIN_YEAR = 1950;


    public function assertAllowed(Birthday $birthday): void
    {
        if ((int) $birthday->getValue()->format('Y') < self::MIN_YEAR) {
            throw InvalidUserValueException::birthdayTooEarly(self::MIN_YEAR);
        }
    }
}
