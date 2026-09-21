<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Rules;

use App\Domain\Import\Rules\CompletenessRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\CandidateParent;

final class CompletenessRuleTest extends TestCase
{
    #[Test]
    public function accepts_complete_title(): void
    {
        self::assertTrue(new CompletenessRule()->evaluate(CandidateParent::create())->isAccepted());
    }

    #[Test]
    public function flags_missing_poster(): void
    {
        $decision = new CompletenessRule()->evaluate(CandidateParent::create(posterUrl: null));

        self::assertTrue($decision->isFlagged());
        self::assertSame(['missing' => ['poster']], $decision->context);
    }

    #[Test]
    public function flags_missing_description_and_poster(): void
    {
        $decision = new CompletenessRule()->evaluate(
            CandidateParent::create(descriptionRu: null, descriptionEn: null, posterUrl: null),
        );

        self::assertTrue($decision->isFlagged());
        self::assertSame(['missing' => ['description', 'poster']], $decision->context);
    }
}
