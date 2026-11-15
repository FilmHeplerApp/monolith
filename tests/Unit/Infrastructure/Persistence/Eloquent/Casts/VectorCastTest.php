<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Persistence\Eloquent\Casts;

use App\Domain\Shared\ValueObjects\Embedding;
use App\Infrastructure\Persistence\Eloquent\Casts\VectorCast;
use App\Infrastructure\Persistence\Eloquent\Models\User;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VectorCastTest extends TestCase
{
    public function test_it_round_trips_arrays_literals_and_null(): void
    {
        $cast = new VectorCast;
        $model = new User;
        $vector = array_fill(0, Embedding::DIMENSION, 0.5);
        $vector[0] = 1;
        $expected = array_map(static fn (int|float $number): float => (float) $number, $vector);
        $literal = json_encode($vector, JSON_THROW_ON_ERROR);

        foreach ([$vector, $literal] as $input) {
            self::assertSame($expected, $cast->get($model, 'embedding', $input, []));
            self::assertSame($expected, $cast->get($model, 'embedding', $cast->set($model, 'embedding', $input, []), []));
        }

        self::assertNull($cast->get($model, 'embedding', null, []));
        self::assertNull($cast->set($model, 'embedding', null, []));
    }

    #[DataProvider('invalidVectors')]
    public function test_it_rejects_invalid_values_on_read_and_write(mixed $value): void
    {
        $cast = new VectorCast;
        $model = new User;
        foreach (['get', 'set'] as $method) {
            try {
                $cast->$method($model, 'embedding', $value, []);
                self::fail('Invalid vector was accepted by '.$method);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Embedding', $exception->getMessage());
            }
        }
    }

    public static function invalidVectors(): iterable
    {
        yield 'short array' => [[1, 2]];
        yield 'short literal' => ['[1,2]'];
        yield 'too long' => [array_fill(0, Embedding::DIMENSION + 1, 0)];
        yield 'malformed JSON' => ['[broken]'];
        yield 'JSON object' => ['{"0": 1}'];
        yield 'JSON null' => ['null'];
        yield 'scalar' => [42];
        yield 'associative array' => [array_fill(1, Embedding::DIMENSION, 0)];

        foreach (['text', '1.5', true, null, [], INF, NAN, 1e100] as $index => $item) {
            $vector = array_fill(0, Embedding::DIMENSION, 0);
            $vector[0] = $item;
            yield 'invalid element '.$index => [$vector];
            if ($index < 5) {
                yield 'invalid literal element '.$index => [json_encode($vector, JSON_THROW_ON_ERROR)];
            }
        }
    }
}
