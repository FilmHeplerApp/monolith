<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\ValueObjects\Shared;

use App\Domain\Catalog\Exceptions\InvalidCatalogValueException;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalizedTextTest extends TestCase
{
    #[Test]
    #[DataProvider('blankTranslations')]
    public function optional_text_is_absent_when_both_translations_are_blank(?string $ru, ?string $en): void
    {
        self::assertNull(LocalizedText::create($ru, $en));
    }

    #[Test]
    #[DataProvider('blankTranslations')]
    public function required_text_rejects_blank_translations(?string $ru, ?string $en): void
    {
        $this->expectException(InvalidCatalogValueException::class);
        $this->expectExceptionMessage('Localized text must contain at least one non-empty translation.');

        LocalizedText::createRequired($ru, $en);
    }

    #[Test]
    #[DataProvider('translations')]
    public function factories_normalize_translations_and_preserve_zero(
        ?string $ru,
        ?string $en,
        ?string $expectedRu,
        ?string $expectedEn,
    ): void {
        $optional = LocalizedText::create($ru, $en);
        self::assertNotNull($optional);

        foreach ([$optional, LocalizedText::createRequired($ru, $en)] as $text) {
            self::assertSame($expectedRu, $text->getRu());
            self::assertSame($expectedEn, $text->getEn());
            self::assertSame($expectedRu ?? $expectedEn, $text->getPreferred());
            self::assertSame($expectedEn ?? $expectedRu, $text->getPreferred('en'));
            self::assertSame($expectedEn ?? $expectedRu, $text->getPreferred('EN'));
            self::assertSame($expectedRu ?? $expectedEn, $text->getPreferred('unknown'));
        }
    }

    public static function blankTranslations(): array
    {
        return [
            'absent' => [null, null],
            'empty' => ['', ''],
            'blank russian' => [" \t\n", null],
            'blank english' => [null, " \r\n"],
            'both blank' => [" \t", "\n "],
        ];
    }

    public static function translations(): array
    {
        return [
            'both languages' => [' Название ', "\tTitle\n", 'Название', 'Title'],
            'russian only' => [' Название ', null, 'Название', null],
            'english only' => [null, ' Title ', null, 'Title'],
            'blank english' => ['Название', " \t", 'Название', null],
            'blank russian' => ["\n ", 'Title', null, 'Title'],
            'russian zero' => [' 0 ', null, '0', null],
            'english zero' => [null, ' 0 ', null, '0'],
            'zero takes precedence over fallback' => ['0', 'Zero', '0', 'Zero'],
        ];
    }
}
