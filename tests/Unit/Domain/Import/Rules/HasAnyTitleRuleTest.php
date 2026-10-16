<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Rules;

use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Rules\HasAnyTitleRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\TitleCandidateFactory;

final class HasAnyTitleRuleTest extends TestCase
{
    #[Test]
    #[DataProvider('singleLanguageTitles')]
    public function accepts_a_title_in_either_language(?string $ru, ?string $en): void
    {
        self::assertTrue(new HasAnyTitleRule()->evaluate(TitleCandidateFactory::create(titleRu: $ru, titleEn: $en))->isAccepted());
    }

    public static function singleLanguageTitles(): array
    {
        return ['only ru' => ['Название', null], 'only en' => [null, 'Title']];
    }

    #[Test]
    public function accepts_when_title_present(): void
    {
        self::assertTrue(new HasAnyTitleRule()->evaluate(TitleCandidateFactory::create())->isAccepted());
    }

    #[Test]
    public function rejects_when_no_title(): void
    {
        $decision = new HasAnyTitleRule()->evaluate(TitleCandidateFactory::create(titleRu: null, titleEn: null));

        self::assertTrue($decision->isRejected());
        self::assertSame(RejectionReason::NO_TITLE, $decision->reason);
    }
}
