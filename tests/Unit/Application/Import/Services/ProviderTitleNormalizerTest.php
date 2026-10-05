<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\Services;

use App\Application\Import\Services\ProviderTitleNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderTitleNormalizerTest extends TestCase
{
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
    public function it_strips_entity_encoded_html_but_keeps_inner_text(): void
    {
        self::assertSame('текст', $this->normalizer()->normalizeDescription('&lt;b&gt;текст&lt;/b&gt;'));
    }

    #[Test]
    public function it_returns_null_for_empty_entity_encoded_html(): void
    {
        self::assertNull($this->normalizer()->normalizeDescription('&lt;b&gt;&lt;/b&gt;'));
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
    public function it_truncates_text_without_spaces_within_the_limit(): void
    {
        $result = $this->normalizer()->normalizeDescription(str_repeat('я', 1001));

        self::assertSame(str_repeat('я', 999).'…', $result);
        self::assertSame(1000, mb_strlen($result));
    }

    #[Test]
    public function it_keeps_text_at_the_length_limit(): void
    {
        $text = str_repeat('я', 1000);

        self::assertSame($text, $this->normalizer()->normalizeDescription($text));
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

    private function normalizer(int $max = 1000): ProviderTitleNormalizer
    {
        return new ProviderTitleNormalizer($max);
    }
}
