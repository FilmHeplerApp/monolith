<?php

declare(strict_types=1);

namespace App\Domain\Users\ValueObjects\User;

use App\Domain\Users\Exceptions\InvalidUserValueException;

final readonly class PasswordHash
{
    public static function createFromHash(string $hash): self
    {
        if (trim($hash) === '') {
            throw InvalidUserValueException::blankPasswordHash();
        }

        return new self($hash);
    }

    public function getValue(): string
    {
        return $this->value;
    }


    private function __construct(
        private string $value,
    ) {
    }
}
