<?php

declare(strict_types=1);

namespace Database\Factories\Fixtures;

use App\Domain\Shared\ValueObjects\Embedding;

final class EmbeddingFixture
{
    public static function vector(): array
    {
        $vector = range(0, Embedding::DIMENSION - 1);
        shuffle($vector);

        return $vector;
    }
}
