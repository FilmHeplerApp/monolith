<?php

declare(strict_types=1);

namespace Tests\Feature\Factories;

use App\Domain\Shared\ValueObjects\Embedding;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use App\Infrastructure\Persistence\Eloquent\Models\User;
use Database\Factories\Fixtures\EmbeddingFixture;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EmbeddingFactoriesTest extends TestCase
{
    #[Test]
    public function the_fixture_builds_a_valid_embedding_vector(): void
    {
        $vector = EmbeddingFixture::vector();

        self::assertCount(Embedding::DIMENSION, $vector);
        self::assertSame(range(0, Embedding::DIMENSION - 1), $this->sorted($vector));
        self::assertNotNull(Embedding::createFromArray($vector));
    }

    #[Test]
    public function the_user_factory_assigns_an_embedding_value_object(): void
    {
        $user = User::factory()->make();

        self::assertInstanceOf(Embedding::class, $user->embedding);
        self::assertCount(Embedding::DIMENSION, $user->embedding->getVector());
    }

    #[Test]
    public function the_title_factory_assigns_a_vector_of_the_embedding_dimension(): void
    {
        $title = Title::factory()->make();

        self::assertIsArray($title->embedding);
        self::assertNotNull(Embedding::createFromArray($title->embedding));
    }


    private function sorted(array $vector): array
    {
        sort($vector);

        return $vector;
    }
}
