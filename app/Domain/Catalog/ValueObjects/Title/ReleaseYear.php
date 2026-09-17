<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\Title;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class ReleaseYear
{
    public const int MIN_YEAR = 1888;
    public const int MAX_YEAR = 2100;


    private function __construct(
        private int $year,
    ) {
    }


    public static function createFromYear(?int $year): ?self
    {
        if ($year === null) {
            return null;
        }

        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw InvalidCatalogValueException::releaseYearOutOfRange($year);
        }

        return new self($year);
    }

    public function getYear(): int
    {
        return $this->year;
    }
}
