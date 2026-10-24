<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $this->floats($value);
        }

        $decoded = json_decode((string)$value, true);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Embedding column is not a vector.');
        }

        return $this->floats($decoded);
    }

    public function set(
        Model  $model,
        string $key,
        mixed  $value,
        array  $attributes,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Embedding must be a list of numbers or a vector literal.');
        }

        return json_encode($this->floats($value), JSON_THROW_ON_ERROR);
    }


    /**
     * @param array<mixed> $values
     * @return list<float>
     */
    private function floats(array $values): array
    {
        return array_map(static fn(mixed $item): float => (float)$item, array_values($values));
    }
}
