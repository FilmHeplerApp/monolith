<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidEmbeddingException;

final readonly class Embedding
{
    public const int DIMENSION = 1536;
    /** Largest finite float32 magnitude accepted by pgvector. */
    public const float MAX_ABS_VALUE = 3.4028234663852886e38;


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

        $floats = array_map(static fn(float|int $value): float => (float)$value, $vector);

        foreach ($floats as $index => $value) {
            if (!is_finite($value) || abs($value) > self::MAX_ABS_VALUE) {
                throw InvalidEmbeddingException::invalidValue($index);
            }
        }

        return new self($floats);
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
