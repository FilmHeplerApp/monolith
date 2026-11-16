<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidEmbeddingException;
use App\Domain\Shared\ValueObjects\Embedding;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    public function embedding_accepts_the_float32_boundary_values(): void
    {
        $vector = array_fill(0, Embedding::DIMENSION, 0.0);
        $vector[0] = Embedding::MAX_ABS_VALUE;
        $vector[1] = -Embedding::MAX_ABS_VALUE;

        $embedding = Embedding::createFromArray($vector);

        $this->assertNotNull($embedding);
        $this->assertSame(Embedding::MAX_ABS_VALUE, $embedding->getVector()[0]);
        $this->assertSame(-Embedding::MAX_ABS_VALUE, $embedding->getVector()[1]);
    }

    #[Test]
    #[DataProvider('invalidElements')]
    public function embedding_rejects_a_non_finite_or_out_of_float32_range_element(float $element): void
    {
        $vector = array_fill(0, Embedding::DIMENSION, 0.0);
        $vector[7] = $element;

        $this->expectException(InvalidEmbeddingException::class);
        $this->expectExceptionMessage('Embedding element [7] must be a finite float32 number.');

        Embedding::createFromArray($vector);
    }

    public static function invalidElements(): iterable
    {
        yield 'NaN' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'above float32 max' => [3.5e38];
        yield 'below float32 min' => [-3.5e38];
    }
}
