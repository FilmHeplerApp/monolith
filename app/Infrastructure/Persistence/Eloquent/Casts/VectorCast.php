<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Casts;

use App\Domain\Shared\ValueObjects\Embedding;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * @implements CastsAttributes<Embedding, Embedding>
 */
final class VectorCast implements CastsAttributes
{
    public function get(
        Model  $model,
        string $key,
        mixed  $value,
        array  $attributes,
    ): ?Embedding {
        if ($value === null) {
            return null;
        }

        return Embedding::createFromArray($this->decode($value));
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
        if ($value === null) {
            return null;
        }

        if (!$value instanceof Embedding) {
            throw new InvalidArgumentException(sprintf('Embedding attribute accepts only %s.', Embedding::class));
        }

        return json_encode($value->getVector(), JSON_THROW_ON_ERROR);
    }


    /** @return array<mixed> */
    private function decode(mixed $value): array
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Embedding must be stored as a vector literal.');
        }

        try {
            $vector = json_decode($value, associative: false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Embedding must be a valid vector literal.', previous: $exception);
        }

        if (!is_array($vector)) {
            throw new InvalidArgumentException('Embedding must be a valid vector literal.');
        }

        return $vector;
    }
}
