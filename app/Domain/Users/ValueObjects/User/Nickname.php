<?php

declare(strict_types=1);

namespace App\Domain\Users\ValueObjects\User;

use App\Domain\Users\Exceptions\InvalidUserValueException;

final readonly class Nickname
{
    public const int MAX_LENGTH = 32;


    public static function createFromString(string $value): self
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw InvalidUserValueException::blankNickname();
        }

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw InvalidUserValueException::nicknameTooLong(self::MAX_LENGTH);
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
