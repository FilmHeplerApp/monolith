<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidEmbeddingException;

final readonly class Embedding
{
    public const int DIMENSION = 1536;


    /**
     * @param list<float|int>|null $vector
     */
    public static function createFromArray(?array $vector): ?self
    {
        if ($vector === null) {
            return null;
        }

        if (count($vector) !== self::DIMENSION) {
            throw InvalidEmbeddingException::invalidDimension(self::DIMENSION, count($vector));
        }

        return new self(array_map(static fn(float|int $value): float => (float)$value, $vector));
    }

    /**
     * @return list<float>
     */
    public function getVector(): array
    {
        return $this->vector;
    }


    /**
     * @param list<float> $vector
     */
    private function __construct(
        private array $vector,
    ) {
    }
}
