<?php

declare(strict_types=1);

namespace App\Domain\Users\ValueObjects\User;

use App\Domain\Users\Exceptions\InvalidUserValueException;

final readonly class Email
{
    private function __construct(
        private string $value,
    ) {
    }


    public static function createFromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            throw InvalidUserValueException::blankEmail();
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
}
