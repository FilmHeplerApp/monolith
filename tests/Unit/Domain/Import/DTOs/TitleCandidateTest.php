<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\DTOs;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Import\DTOs\CandidateAttribute;
use App\Domain\Import\DTOs\TitleCandidate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\CandidateParent;

class TitleCandidateTest extends TestCase
{
    #[Test]
    public function it_exposes_provided_values(): void
    {
        $candidate = new TitleCandidate(
            source: 'shikimori',
            externalId: '52991',
            title: LocalizedText::create('Стальной алхимик', 'Fullmetal Alchemist'),
            description: null,
            type: TitleContentType::ANIME,
            status: TitleStatus::RELEASED,
            releaseYear: 2009,
            providerScore: 9.1,
            providerScoreCount: 500000,
            durationMinutes: 24,
            posterUrl: 'https://mock.local/52991.jpg',
            bannerUrl: null,
        );

        self::assertSame(TitleContentType::ANIME, $candidate->type);
        self::assertSame('Fullmetal Alchemist', $candidate->title?->getEn());
        self::assertNull($candidate->description);
    }

    #[Test]
    public function title_is_null_when_no_names_given(): void
    {
        $candidate = new TitleCandidate(
            source: 'shikimori',
            externalId: '1',
            title: LocalizedText::create(null, null),
            description: null,
            type: TitleContentType::ANIME,
            status: TitleStatus::RELEASED,
            releaseYear: null,
            providerScore: null,
            providerScoreCount: null,
            durationMinutes: null,
            posterUrl: null,
            bannerUrl: null,
        );

        self::assertNull($candidate->title);
    }

    #[Test]
    public function it_defaults_to_no_attributes(): void
    {
        self::assertSame([], CandidateParent::create()->attributes);
    }

    #[Test]
    public function it_carries_attributes(): void
    {
        $genres = new CandidateAttribute('genres', [
            LocalizedText::create('Экшен', 'Action'),
            LocalizedText::create('Фантастика', 'Sci-Fi'),
        ]);

        $candidate = CandidateParent::create(attributes: [$genres]);

        self::assertCount(1, $candidate->attributes);
        self::assertSame('genres', $candidate->attributes[0]->code);
        self::assertSame('Action', $candidate->attributes[0]->values[0]->getEn());
        self::assertSame('Sci-Fi', $candidate->attributes[0]->values[1]->getEn());
    }
}
