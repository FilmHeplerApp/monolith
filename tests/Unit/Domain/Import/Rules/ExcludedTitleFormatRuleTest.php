<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import\Rules;

use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Rules\ExcludedTitleFormatRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Import\Support\TitleCandidateFactory;

final class ExcludedTitleFormatRuleTest extends TestCase
{
    #[Test]
    #[DataProvider('formats')]
    public function evaluates_formats_using_the_current_catalog_policy(
        ?TitleFormat $format,
        bool         $excluded,
    ): void {
        $decision = new ExcludedTitleFormatRule()->evaluate(TitleCandidateFactory::create(format: $format));

        if ($excluded) {
            self::assertTrue($decision->isRejected());
            self::assertSame(RejectionReason::UNSUPPORTED_FORMAT, $decision->reason);
            self::assertSame(['format' => $format->value], $decision->context);
        } else {
            self::assertTrue($decision->isAccepted());
        }
    }

    public static function formats(): array
    {
        $formats = ['unknown' => [null, false]];

        foreach (TitleFormat::cases() as $format) {
            $formats[$format->value] = [$format, in_array($format, [TitleFormat::PV, TitleFormat::CM, TitleFormat::MUSIC], true)];
        }

        return $formats;
    }

    #[Test]
    public function allows_changing_the_excluded_formats_without_changing_the_enum(): void
    {
        $rule = new ExcludedTitleFormatRule([TitleFormat::TV_SHORT]);

        self::assertTrue($rule->evaluate(TitleCandidateFactory::create(format: TitleFormat::TV_SHORT))->isRejected());
        self::assertTrue($rule->evaluate(TitleCandidateFactory::create(format: TitleFormat::PV))->isAccepted());
    }
}
