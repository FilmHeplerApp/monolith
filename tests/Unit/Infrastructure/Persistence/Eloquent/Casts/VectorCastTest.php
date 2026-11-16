<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Persistence\Eloquent\Casts;

use App\Domain\Shared\Exceptions\InvalidEmbeddingException;
use App\Domain\Shared\ValueObjects\Embedding;
use App\Infrastructure\Persistence\Eloquent\Casts\VectorCast;
use App\Infrastructure\Persistence\Eloquent\Models\User;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VectorCastTest extends TestCase
{
    #[Test]
    public function it_round_trips_an_embedding_through_a_vector_literal(): void
    {
        $cast = new VectorCast;
        $model = new User;
        $vector = array_fill(0, Embedding::DIMENSION, 0.5);
        $vector[0] = 1;
        $embedding = Embedding::createFromArray($vector);

        $literal = $cast->set($model, 'embedding', $embedding, []);
        $restored = $cast->get($model, 'embedding', $literal, []);

        self::assertSame(json_encode($embedding?->getVector(), JSON_THROW_ON_ERROR), $literal);
        self::assertInstanceOf(Embedding::class, $restored);
        self::assertSame($embedding?->getVector(), $restored->getVector());
    }

    #[Test]
    public function it_reads_a_pgvector_literal_with_integers(): void
    {
        $vector = array_fill(0, Embedding::DIMENSION, 0);
        $vector[0] = 3;

        $restored = (new VectorCast)->get(new User, 'embedding', json_encode($vector, JSON_THROW_ON_ERROR), []);

        self::assertSame(3.0, $restored?->getVector()[0]);
    }

    #[Test]
    public function it_passes_null_through(): void
    {
        $cast = new VectorCast;
        $model = new User;

        self::assertNull($cast->get($model, 'embedding', null, []));
        self::assertNull($cast->set($model, 'embedding', null, []));
    }

    #[Test]
    #[DataProvider('notEmbeddings')]
    public function it_writes_only_an_embedding(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Embedding attribute accepts only '.Embedding::class.'.');

        (new VectorCast)->set(new User, 'embedding', $value, []);
    }

    public static function notEmbeddings(): iterable
    {
        yield 'list of numbers' => [array_fill(0, Embedding::DIMENSION, 0.5)];
        yield 'vector literal' => ['[1,2]'];
        yield 'scalar' => [42];
    }

    #[Test]
    #[DataProvider('malformedLiterals')]
    public function it_rejects_a_stored_value_that_is_not_a_vector_literal(mixed $value): void
    {
        try {
            (new VectorCast)->get(new User, 'embedding', $value, []);
            self::fail('A malformed stored value must not be accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotInstanceOf(InvalidEmbeddingException::class, $exception);
            self::assertStringStartsWith('Embedding must be', $exception->getMessage());
        }
    }

    public static function malformedLiterals(): iterable
    {
        yield 'malformed JSON' => ['[broken]'];
        yield 'JSON object' => ['{"0": 1}'];
        yield 'JSON null' => ['null'];
        yield 'JSON scalar' => ['42'];
        yield 'not a string' => [42];
    }

    #[Test]
    #[DataProvider('invalidVectorLiterals')]
    public function it_delegates_value_validation_to_the_embedding(string $literal, string $message): void
    {
        $this->expectException(InvalidEmbeddingException::class);
        $this->expectExceptionMessage($message);

        (new VectorCast)->get(new User, 'embedding', $literal, []);
    }

    public static function invalidVectorLiterals(): iterable
    {
        yield 'too short' => ['[1,2]', sprintf('Embedding must have %d dimensions, got 2.', Embedding::DIMENSION)];
        yield 'too long' => [
            json_encode(array_fill(0, Embedding::DIMENSION + 1, 0), JSON_THROW_ON_ERROR),
            sprintf('Embedding must have %d dimensions, got %d.', Embedding::DIMENSION, Embedding::DIMENSION + 1),
        ];

        foreach (['text' => '"text"', 'numeric string' => '"1.5"', 'boolean' => 'true', 'null' => 'null', 'out of float32 range' => '1e100'] as $name => $element) {
            $elements = array_fill(0, Embedding::DIMENSION, '0');
            $elements[5] = $element;

            yield 'invalid element: '.$name => ['['.implode(',', $elements).']', 'Embedding element [5] must be a finite float32 number.'];
        }
    }
}
