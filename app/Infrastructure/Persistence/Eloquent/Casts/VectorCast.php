<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Casts;

use App\Domain\Shared\ValueObjects\Embedding;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * @implements CastsAttributes<list<float>|null, list<float|int>|string|null>
 */
final class VectorCast implements CastsAttributes
{
    public function get(
        Model  $model,
        string $key,
        mixed  $value,
        array  $attributes,
    ): ?array {
        return $value === null ? null : $this->floats($value);
    }

    /**
     * @throws JsonException
     */
    public function set(
        Model  $model,
        string $key,
        mixed  $value,
        array  $attributes,
    ): ?string {
        return $value === null ? null : json_encode($this->floats($value), JSON_THROW_ON_ERROR);
    }


    /** @return list<float> */
    private function floats(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, associative: false, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Embedding must be a valid vector literal.', previous: $exception);
            }
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Embedding must be a list of numbers or a vector literal.');
        }

        if (count($value) !== Embedding::DIMENSION) {
            throw new InvalidArgumentException(sprintf('Embedding must contain exactly %d numbers.', Embedding::DIMENSION));
        }

        foreach ($value as $index => $item) {
            if ((!is_int($item) && !is_float($item)) || !is_finite((float) $item) || abs((float) $item) > 3.4028234663852886e38) {
                throw new InvalidArgumentException(sprintf('Embedding element [%d] must be a finite float32 number.', $index));
            }

            $value[$index] = (float) $item;
        }

        return $value;
    }
}
