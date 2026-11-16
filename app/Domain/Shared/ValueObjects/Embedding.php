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
     * @param  array<mixed>|null  $vector
     */
    public static function createFromArray(?array $vector): ?self
    {
        if ($vector === null) {
            return null;
        }

        if (!array_is_list($vector)) {
            throw InvalidEmbeddingException::notAList();
        }

        if (count($vector) !== self::DIMENSION) {
            throw InvalidEmbeddingException::invalidDimension(self::DIMENSION, count($vector));
        }

        $floats = [];

        foreach ($vector as $index => $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs((float) $value) > self::MAX_ABS_VALUE) {
                throw InvalidEmbeddingException::invalidValue($index);
            }

            $floats[] = (float) $value;
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
     * @param  list<float>  $vector
     */
    private function __construct(
        private array $vector,
    ) {
    }
}
