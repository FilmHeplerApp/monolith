<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\Title;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class TitleRating
{
    private const float MIN_AVERAGE = 0.0;
    private const float MAX_AVERAGE = 10.0;


    private function __construct(
        private float $average,
        private int $count,
    ) {
    }


    public static function create(float $average, int $count): self
    {
        if ($average < self::MIN_AVERAGE || $average > self::MAX_AVERAGE) {
            throw InvalidCatalogValueException::ratingOutOfRange($average);
        }

        if ($count < 0) {
            throw InvalidCatalogValueException::negativeRatingCount($count);
        }

        return new self($average, $count);
    }

    public static function createEmpty(): self
    {
        return new self(0.0, 0);
    }

    public function getAverage(): float
    {
        return $this->average;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
