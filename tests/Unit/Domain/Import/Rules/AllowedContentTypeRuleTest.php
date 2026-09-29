<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Rules;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Rules\AllowedContentTypeRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\CandidateParent;

final class AllowedContentTypeRuleTest extends TestCase
{
    #[Test]
    public function accepts_allowed_type(): void
    {
        $rule = new AllowedContentTypeRule([TitleContentType::ANIME]);

        self::assertTrue($rule->evaluate(CandidateParent::create(type: TitleContentType::ANIME))->isAccepted());
    }

    #[Test]
    public function rejects_disallowed_type(): void
    {
        $rule = new AllowedContentTypeRule([TitleContentType::ANIME]);
        $decision = $rule->evaluate(CandidateParent::create(type: TitleContentType::MOVIE));

        self::assertTrue($decision->isRejected());
        self::assertSame(RejectionReason::UNSUPPORTED_TYPE, $decision->reason);
        self::assertSame(['type' => 'movie'], $decision->context);
    }
}
