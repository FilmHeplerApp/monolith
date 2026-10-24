<?php

declare(strict_types=1);

namespace App\Domain\Users\ValueObjects\UserPreference;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use DateTimeZone;
use Exception;

final readonly class Timezone
{
    public const string DEFAULT = 'Europe/Moscow';


    public static function createFromString(string $value): self
    {
        $normalized = trim($value);

        try {
            new DateTimeZone($normalized);
        } catch (Exception) {
            throw InvalidUserValueException::invalidTimezone($normalized);
        }

        return new self($normalized);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }


    private function __construct(
        private string $value,
    ) {
    }
}
