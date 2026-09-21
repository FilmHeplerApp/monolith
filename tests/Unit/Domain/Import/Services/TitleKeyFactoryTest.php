<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Services;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Import\Services\TitleKeyFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\CandidateParent;

final class TitleKeyFactoryTest extends TestCase
{
    #[Test]
    public function it_is_deterministic(): void
    {
        $factory = new TitleKeyFactory();

        self::assertSame(
            $factory->create(CandidateParent::create())->hash,
            $factory->create(CandidateParent::create())->hash,
        );
    }

    #[Test]
    public function it_builds_readable_natural_key_and_hash(): void
    {
        $key = new TitleKeyFactory()->create(CandidateParent::create(
            titleEn: 'Fullmetal Alchemist',
            type: TitleContentType::ANIME,
            releaseYear: 2009,
        ));

        self::assertSame('anime|2009|fullmetal alchemist', $key->natural);
        self::assertSame(sha1('anime|2009|fullmetal alchemist'), $key->hash);
    }

    #[Test]
    public function it_normalizes_diacritics_punctuation_and_spaces(): void
    {
        $key = new TitleKeyFactory()->create(CandidateParent::create(
            titleEn: '  Pokémon:  The First Movie!! ',
            releaseYear: 1998,
        ));

        self::assertSame('anime|1998|pokemon the first movie', $key->natural);
    }

    #[Test]
    public function it_prefers_english_over_russian(): void
    {
        $key = new TitleKeyFactory()->create(CandidateParent::create(
            titleRu: 'Покемон',
            titleEn: 'Pokemon',
            releaseYear: 1997,
        ));

        self::assertSame('anime|1997|pokemon', $key->natural);
    }

    #[Test]
    public function it_falls_back_to_russian_when_no_english(): void
    {
        $key = new TitleKeyFactory()->create(CandidateParent::create(
            titleRu: 'Наруто',
            titleEn: null,
            releaseYear: 2002,
        ));

        self::assertSame('anime|2002|наруто', $key->natural);
    }

    #[Test]
    public function it_handles_missing_year(): void
    {
        $key = new TitleKeyFactory()->create(CandidateParent::create(
            titleEn: 'Some Title',
            releaseYear: null,
        ));

        self::assertSame('anime||some title', $key->natural);
    }

    #[Test]
    public function score_and_status_do_not_affect_the_key(): void
    {
        $factory = new TitleKeyFactory();

        self::assertSame(
            $factory->create(CandidateParent::create(providerScore: 9.0))->hash,
            $factory->create(CandidateParent::create(providerScore: 3.0))->hash,
        );
    }
}
