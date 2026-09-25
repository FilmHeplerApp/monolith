<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\Services;

use App\Application\Import\Services\ProviderTitleNormalizer;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderTitleNormalizerTest extends TestCase
{
    private function normalizer(int $max = 1000): ProviderTitleNormalizer
    {
        return new ProviderTitleNormalizer($max);
    }

    #[Test]
    public function it_strips_bbcode_but_keeps_inner_text(): void
    {
        self::assertSame(
            'Спайк — охотник.',
            $this->normalizer()->normalizeDescription('[character=42]Спайк[/character] — охотник.'),
        );
    }

    #[Test]
    public function it_strips_html(): void
    {
        self::assertSame('bold text', $this->normalizer()->normalizeDescription('<b>bold</b> text'));
    }

    #[Test]
    public function it_strips_html_encoded_as_entities(): void
    {
        self::assertSame(
            'alert(1) safe text',
            $this->normalizer()->normalizeDescription(
                '&lt;script&gt;alert(1)&lt;/script&gt; &lt;strong&gt;safe&lt;/strong&gt; text',
            ),
        );
    }

    #[Test]
    public function it_decodes_entities(): void
    {
        self::assertSame('Tom & Jerry', $this->normalizer()->normalizeDescription('Tom &amp; Jerry'));
    }

    #[Test]
    public function it_collapses_whitespace(): void
    {
        self::assertSame('a b c', $this->normalizer()->normalizeDescription("a\n\n  b\t c"));
    }

    #[Test]
    public function it_truncates_on_word_boundary(): void
    {
        self::assertSame('one two…', $this->normalizer(10)->normalizeDescription('one two three four'));
    }

    #[Test]
    public function it_returns_null_for_null(): void
    {
        self::assertNull($this->normalizer()->normalizeDescription(null));
    }

    #[Test]
    public function it_returns_null_for_blank(): void
    {
        self::assertNull($this->normalizer()->normalizeDescription('  [b][/b]  '));
    }

    #[Test]
    public function it_normalizes_each_localized_description_independently(): void
    {
        $description = $this->normalizer()->normalizeLocalizedDescription(
            LocalizedText::create('<b>Русское</b> описание', 'English &amp; description'),
        );

        self::assertSame('Русское описание', $description?->getRu());
        self::assertSame('English & description', $description?->getEn());
    }

    #[Test]
    public function it_returns_null_when_all_localized_descriptions_are_blank(): void
    {
        self::assertNull($this->normalizer()->normalizeLocalizedDescription(
            LocalizedText::create('<b></b>', '[description][/description]'),
        ));
    }
}
