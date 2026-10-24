<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidEmbeddingException;
use App\Domain\Shared\ValueObjects\Embedding;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmbeddingTest extends TestCase
{
    #[Test]
    public function embedding_keeps_a_vector_of_the_exact_dimension(): void
    {
        $vector = array_fill(0, Embedding::DIMENSION, 0.1);

        $embedding = Embedding::createFromArray($vector);

        $this->assertNotNull($embedding);
        $this->assertCount(Embedding::DIMENSION, $embedding->getVector());
        $this->assertSame(0.1, $embedding->getVector()[0]);
    }

    #[Test]
    public function embedding_accepts_null(): void
    {
        $this->assertNull(Embedding::createFromArray(null));
    }

    #[Test]
    public function embedding_rejects_a_vector_of_the_wrong_size(): void
    {
        $this->expectException(InvalidEmbeddingException::class);

        Embedding::createFromArray([0.1, 0.2]);
    }
}
