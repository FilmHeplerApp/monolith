<?php

declare(strict_types=1);

namespace App\Domain\Import\ValueObjects;

final readonly class TitleKey
{
    private function __construct(
        public string $natural,
        public string $hash,
    ) {
    }

    public static function fromNatural(string $natural): self
    {
        return new self($natural, sha1($natural));
    }
}
