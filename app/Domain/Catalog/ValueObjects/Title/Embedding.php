<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\Title;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;

final readonly class Embedding
{
    public const int DIMENSION = 1536;

    /**
     * @param list<int> $vector
     */
    private function __construct(
        private array $vector,
    ) {
    }


    /**
     * @param list<int>|null $vector
     */
    public static function createFromArray(?array $vector): ?self
    {
        if ($vector === null) {
            return null;
        }

        if (count($vector) !== self::DIMENSION) {
            throw InvalidCatalogValueException::invalidEmbeddingDimension(self::DIMENSION, count($vector));
        }

        return new self(array_map(static fn (int $value): int => $value, $vector));
    }

    /**
     * @return list<int>
     */
    public function getVector(): array
    {
        return $this->vector;
    }
}
