<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Services;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Domain\Import\Services\TitleKeyFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\TitleCandidateFactory;

final class TitleKeyFactoryTest extends TestCase
{
    #[Test]
    public function it_is_deterministic(): void
    {
        $factory = new TitleKeyFactory;

        self::assertSame(
            $factory->create(TitleCandidateFactory::create())->getValue(),
            $factory->create(TitleCandidateFactory::create())->getValue(),
        );
    }

    #[Test]
    public function it_builds_readable_natural_key_and_hash(): void
    {
        $key = new TitleKeyFactory()->create(TitleCandidateFactory::create(
            titleEn: 'Fullmetal Alchemist',
            type: TitleContentType::ANIME,
            releaseYear: 2009,
        ));

        self::assertSame('anime|tv|2009|fullmetal alchemist', $key->getNatural());
        self::assertSame('v2:'.sha1('anime|tv|2009|fullmetal alchemist'), $key->getValue());
        self::assertSame(TitleKeyFactory::NORMALIZER_VERSION, $key->getNormalizerVersion());
    }

    #[Test]
    public function it_normalizes_diacritics_punctuation_and_spaces(): void
    {
        $key = new TitleKeyFactory()->create(TitleCandidateFactory::create(
            titleEn: '  Pokémon:  The First Movie!! ',
            releaseYear: 1998,
        ));

        self::assertSame('anime|tv|1998|pokemon the first movie', $key->getNatural());
    }

    #[Test]
    public function it_prefers_english_over_russian(): void
    {
        $key = new TitleKeyFactory()->create(TitleCandidateFactory::create(
            titleRu: 'Покемон',
            titleEn: 'Pokemon',
            releaseYear: 1997,
        ));

        self::assertSame('anime|tv|1997|pokemon', $key->getNatural());
    }

    #[Test]
    public function it_falls_back_to_russian_when_no_english(): void
    {
        $key = new TitleKeyFactory()->create(TitleCandidateFactory::create(
            titleRu: 'Наруто',
            titleEn: null,
            releaseYear: 2002,
        ));

        self::assertSame('anime|tv|2002|наруто', $key->getNatural());
    }

    #[Test]
    public function it_handles_missing_year(): void
    {
        $key = new TitleKeyFactory()->create(TitleCandidateFactory::create(
            titleEn: 'Some Title',
            releaseYear: null,
        ));

        self::assertSame('anime|tv||some title', $key->getNatural());
    }

    #[Test]
    public function score_and_status_do_not_affect_the_key(): void
    {
        $factory = new TitleKeyFactory;

        self::assertSame(
            $factory->create(TitleCandidateFactory::create(providerScore: 9.0))->getValue(),
            $factory->create(TitleCandidateFactory::create(providerScore: 3.0))->getValue(),
        );
    }

    #[Test]
    public function it_separates_formats_with_the_same_title_and_year(): void
    {
        $factory = new TitleKeyFactory;
        $keys = array_map(
            static fn (TitleFormat $format): string => $factory->create(TitleCandidateFactory::create(format: $format))->getValue(),
            [TitleFormat::TV, TitleFormat::MOVIE, TitleFormat::TV_SHORT, TitleFormat::TV_SPECIAL],
        );

        self::assertCount(4, array_unique($keys));
    }

    #[Test]
    public function it_keeps_unknown_format_distinct_from_tv(): void
    {
        $factory = new TitleKeyFactory;
        $unknown = $factory->create(TitleCandidateFactory::create(format: null));

        self::assertSame('anime|unknown|2009|fullmetal alchemist', $unknown->getNatural());
        self::assertNotSame($factory->create(TitleCandidateFactory::create(format: TitleFormat::TV))->getValue(), $unknown->getValue());
    }

    #[Test]
    public function the_version_is_part_of_the_persistable_key(): void
    {
        $natural = 'anime|tv|2009|fullmetal alchemist';
        $old = TitleCanonicalKey::createFromNatural($natural, 1);
        $current = TitleCanonicalKey::createFromNatural($natural, TitleKeyFactory::NORMALIZER_VERSION);

        self::assertNotSame($old->getValue(), $current->getValue());
        self::assertStringStartsWith('v1:', $old->getValue());
        self::assertStringStartsWith('v2:', $current->getValue());
    }

    #[Test]
    #[DataProvider('equivalentTitles')]
    public function it_normalizes_equivalent_unicode_titles(string $first, string $second): void
    {
        $factory = new TitleKeyFactory;

        self::assertSame(
            $factory->create(TitleCandidateFactory::create(titleEn: $first))->getValue(),
            $factory->create(TitleCandidateFactory::create(titleEn: $second))->getValue(),
        );
    }

    public static function equivalentTitles(): array
    {
        return [
            'diacritics' => ['Pokémon', 'Pokemon'],
            'combining diacritics' => ["Poke\u{0301}mon", 'Pokemon'],
            'cyrillic yo' => ['Ёлка', 'Елка'],
            'fullwidth latin' => ['Ｐｏｋｅｍｏｎ', 'Pokemon'],
            'ligature' => ['ﬁlm', 'film'],
        ];
    }
}
