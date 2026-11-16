<?php

declare(strict_types=1);

namespace App\Domain\Users\Services;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\ValueObjects\UserPreference\Timezone;
use DateTimeZone;

final readonly class TimezonePolicy
{
    public function assertAllowed(Timezone $timezone): void
    {
        if (!in_array($timezone->getValue(), DateTimeZone::listIdentifiers(), true)) {
            throw InvalidUserValueException::outdatedTimezone($timezone->getValue());
        }
    }
}
