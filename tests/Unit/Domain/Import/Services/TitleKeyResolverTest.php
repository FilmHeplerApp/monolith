<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Services;

use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Domain\Import\DTOs\TitleIdentity;
use App\Domain\Import\Services\TitleKeyFactory;
use App\Domain\Import\Services\TitleKeyResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\TitleCandidateFactory;

final class TitleKeyResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_distinct_formats_without_merging_them(): void
    {
        $tv = TitleCandidateFactory::create(externalId: '1', format: TitleFormat::TV);
        $movie = TitleCandidateFactory::create(externalId: '2', format: TitleFormat::MOVIE);
        $short = TitleCandidateFactory::create(externalId: '3', format: TitleFormat::TV_SHORT);

        $result = $this->resolver()->resolve([$tv, $movie, $short]);

        self::assertCount(3, $result->resolved);
        self::assertSame([], $result->collisions);
    }

    #[Test]
    public function it_reports_all_new_identities_sharing_a_key(): void
    {
        $first = TitleCandidateFactory::create(externalId: '1');
        $second = TitleCandidateFactory::create(externalId: '2');
        $third = TitleCandidateFactory::create(source: 'another-provider', externalId: '1');
        $unrelated = TitleCandidateFactory::create(titleEn: 'Another title', externalId: '4');

        $result = $this->resolver()->resolve([$first, $second, $third, $unrelated]);

        self::assertCount(1, $result->resolved);
        self::assertSame($unrelated, $result->resolved[0]->candidate);
        self::assertCount(1, $result->collisions);
        self::assertCount(3, $result->collisions[0]->identities);
        self::assertSame('v2:'.sha1('anime|tv|2009|fullmetal alchemist'), $result->collisions[0]->key->getValue());
        self::assertSame('another-provider', $result->collisions[0]->identities[2]->source);
    }

    #[Test]
    public function it_reports_same_title_without_year_instead_of_silently_merging(): void
    {
        $result = $this->resolver()->resolve([
            TitleCandidateFactory::create(releaseYear: null, externalId: '1'),
            TitleCandidateFactory::create(releaseYear: null, externalId: '2'),
        ]);

        self::assertSame([], $result->resolved);
        self::assertCount(1, $result->collisions);
        self::assertSame('anime|tv||fullmetal alchemist', $result->collisions[0]->key->getNatural());
        self::assertSame('1', $result->collisions[0]->identities[0]->externalId);
        self::assertSame('2', $result->collisions[0]->identities[1]->externalId);
    }

    #[Test]
    public function it_reports_a_collision_with_an_existing_title_and_keeps_its_update(): void
    {
        $known = TitleCandidateFactory::create(externalId: '1');
        $new = TitleCandidateFactory::create(externalId: '2');
        $key = new TitleKeyFactory()->create($known);
        $existing = new TitleIdentity($known->source, $known->externalId, $key);

        $result = $this->resolver()->resolve([$known, $new], [$existing]);

        self::assertCount(1, $result->resolved);
        self::assertSame($known, $result->resolved[0]->candidate);
        self::assertCount(1, $result->collisions);
        self::assertSame($key->getValue(), $result->collisions[0]->key->getValue());
        self::assertSame($existing, $result->collisions[0]->identities[0]);
        self::assertSame('2', $result->collisions[0]->identities[1]->externalId);
    }

    #[Test]
    public function it_preserves_the_stored_key_after_a_rename_and_version_change(): void
    {
        $natural = 'anime|2009|fullmetal alchemist';
        $legacyKey = TitleCanonicalKey::createFromString(sha1($natural), natural: $natural, normalizerVersion: 1);
        $renamed = TitleCandidateFactory::create(titleEn: 'Renamed title', releaseYear: 2010, format: TitleFormat::MOVIE);
        $existing = new TitleIdentity($renamed->source, $renamed->externalId, $legacyKey);

        $result = $this->resolver()->resolve([$renamed], [$existing]);

        self::assertCount(1, $result->resolved);
        self::assertSame($legacyKey, $result->resolved[0]->key);
        self::assertSame(sha1($natural), $result->resolved[0]->key->getValue());
        self::assertSame(1, $result->resolved[0]->key->getNormalizerVersion());
        self::assertSame([], $result->collisions);
    }

    #[Test]
    public function it_deduplicates_only_repeated_provider_identities(): void
    {
        $candidate = TitleCandidateFactory::create();
        $duplicate = TitleCandidateFactory::create(titleEn: 'Renamed within batch');

        $result = $this->resolver()->resolve([$candidate, $duplicate]);

        self::assertCount(1, $result->resolved);
        self::assertSame($candidate, $result->resolved[0]->candidate);
        self::assertSame([], $result->collisions);
    }

    #[Test]
    public function it_preserves_explicitly_linked_existing_provider_identities(): void
    {
        $first = TitleCandidateFactory::create(source: 'shikimori', externalId: '1');
        $second = TitleCandidateFactory::create(source: 'another-provider', externalId: '2');
        $key = new TitleKeyFactory()->create($first);

        $result = $this->resolver()->resolve([$first, $second], [
            new TitleIdentity($first->source, $first->externalId, $key),
            new TitleIdentity($second->source, $second->externalId, $key),
        ]);

        self::assertCount(2, $result->resolved);
        self::assertSame($key, $result->resolved[0]->key);
        self::assertSame($key, $result->resolved[1]->key);
        self::assertSame([], $result->collisions);
    }

    #[Test]
    public function it_reports_collisions_when_format_is_unknown(): void
    {
        $result = $this->resolver()->resolve([
            TitleCandidateFactory::create(externalId: '1', format: null),
            TitleCandidateFactory::create(externalId: '2', format: null),
        ]);

        self::assertSame([], $result->resolved);
        self::assertCount(1, $result->collisions);
        self::assertSame('anime|unknown|2009|fullmetal alchemist', $result->collisions[0]->key->getNatural());
    }

    #[Test]
    public function it_resolves_an_empty_batch(): void
    {
        $result = $this->resolver()->resolve([]);

        self::assertSame([], $result->resolved);
        self::assertSame([], $result->collisions);
    }

    #[Test]
    public function it_reports_equivalent_unicode_titles_from_different_identities(): void
    {
        $result = $this->resolver()->resolve([
            TitleCandidateFactory::create(titleEn: 'Pokémon', externalId: '1'),
            TitleCandidateFactory::create(titleEn: 'Pokemon', externalId: '2'),
        ]);

        self::assertSame([], $result->resolved);
        self::assertCount(1, $result->collisions);
        self::assertCount(2, $result->collisions[0]->identities);
    }

    #[Test]
    public function it_distinguishes_provider_identities_containing_delimiters(): void
    {
        $result = $this->resolver()->resolve([
            TitleCandidateFactory::create(source: 'provider|part', externalId: '1'),
            TitleCandidateFactory::create(source: 'provider', externalId: 'part|1'),
        ]);

        self::assertSame([], $result->resolved);
        self::assertCount(1, $result->collisions);
        self::assertCount(2, $result->collisions[0]->identities);
    }


    private function resolver(): TitleKeyResolver
    {
        return new TitleKeyResolver(new TitleKeyFactory);
    }
}
