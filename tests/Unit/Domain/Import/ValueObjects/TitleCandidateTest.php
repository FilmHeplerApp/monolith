<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\ValueObjects;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Import\ValueObjects\TitleCandidate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
