<?php

declare(strict_types=1);

namespace App\Domain\Users\ValueObjects\User;

use App\Domain\Users\Exceptions\InvalidUserValueException;

final readonly class SecretPhraseHash
{
    public static function createFromHash(string $hash): self
    {
        if (trim($hash) === '') {
            throw InvalidUserValueException::blankSecretPhraseHash();
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
