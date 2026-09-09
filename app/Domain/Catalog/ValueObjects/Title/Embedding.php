<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\Title;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class Embedding
{
    public const int DIMENSION = 1536;

    /**
     * @param list<float> $vector
     */
    private function __construct(
        private array $vector,
    ) {
    }

    /**
     * @param list<float|int>|null $vector
     */
    public static function createFromArray(?array $vector): ?self
    {
        if ($vector === null) {
            return null;
        }

        if (count($vector) !== self::DIMENSION) {
            throw InvalidCatalogValueException::invalidEmbeddingDimension(self::DIMENSION, count($vector));
        }

        return new self(array_map(static fn (float|int $value): float => (float) $value, $vector));
    }

    /**
     * @return list<float>
     */
    public function getVector(): array
    {
        return $this->vector;
    }
}
