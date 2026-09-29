<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Rules;

use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Rules\HasAnyTitleRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\CandidateParent;

final class HasAnyTitleRuleTest extends TestCase
{
    #[Test]
    public function accepts_when_title_present(): void
    {
        self::assertTrue(new HasAnyTitleRule()->evaluate(CandidateParent::create())->isAccepted());
    }

    #[Test]
    public function rejects_when_no_title(): void
    {
        $decision = new HasAnyTitleRule()->evaluate(CandidateParent::create(titleRu: null, titleEn: null));

        self::assertTrue($decision->isRejected());
        self::assertSame(RejectionReason::NO_TITLE, $decision->reason);
    }
}
