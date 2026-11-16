<?php

declare(strict_types=1);

namespace App\Domain\Users\ValueObjects\User;

use App\Domain\Users\Exceptions\InvalidUserValueException;

final readonly class Email
{
    public const int MAX_LENGTH = 254;


    public static function createFromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            throw InvalidUserValueException::blankEmail();
        }

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw InvalidUserValueException::emailTooLong(self::MAX_LENGTH);
        }

        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw InvalidUserValueException::invalidEmail($normalized);
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
