<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Rules;

use App\Domain\Import\Rules\CompletenessRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\TitleCandidateFactory;

final class CompletenessRuleTest extends TestCase
{
    #[Test]
    #[DataProvider('blankPosters')]
    public function flags_blank_posters(string $poster): void
    {
        $decision = new CompletenessRule()->evaluate(TitleCandidateFactory::create(posterUrl: $poster));

        self::assertTrue($decision->isFlagged());
        self::assertSame(['missing' => ['poster']], $decision->context);
    }

    public static function blankPosters(): array
    {
        return [[''], ['  '], ["\t\n"]];
    }

    #[Test]
    public function flags_only_description_when_poster_is_present(): void
    {
        $decision = new CompletenessRule()->evaluate(TitleCandidateFactory::create(descriptionRu: null, descriptionEn: null));

        self::assertTrue($decision->isFlagged());
        self::assertSame(['missing' => ['description']], $decision->context);
    }

    #[Test]
    public function missing_banner_does_not_make_a_title_incomplete(): void
    {
        self::assertTrue(new CompletenessRule()->evaluate(TitleCandidateFactory::create(bannerUrl: null))->isAccepted());
    }

    #[Test]
    public function accepts_complete_title(): void
    {
        self::assertTrue(new CompletenessRule()->evaluate(TitleCandidateFactory::create())->isAccepted());
    }

    #[Test]
    public function flags_missing_poster(): void
    {
        $decision = new CompletenessRule()->evaluate(TitleCandidateFactory::create(posterUrl: null));

        self::assertTrue($decision->isFlagged());
        self::assertSame(['missing' => ['poster']], $decision->context);
    }

    #[Test]
    public function flags_missing_description_and_poster(): void
    {
        $decision = new CompletenessRule()->evaluate(
            TitleCandidateFactory::create(descriptionRu: null, descriptionEn: null, posterUrl: null),
        );

        self::assertTrue($decision->isFlagged());
        self::assertSame(['missing' => ['description', 'poster']], $decision->context);
    }
}
