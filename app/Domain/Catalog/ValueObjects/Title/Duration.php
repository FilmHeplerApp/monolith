<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\Title;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class Duration
{
    private function __construct(
        private int $minutes,
    ) {
    }


    public static function createFromMinutes(?int $minutes): ?self
    {
        if ($minutes === null) {
            return null;
        }

        if ($minutes <= 0) {
            throw InvalidCatalogValueException::nonPositiveDuration($minutes);
        }

        return new self($minutes);
    }

    public function getMinutes(): int
    {
        return $this->minutes;
    }
}
