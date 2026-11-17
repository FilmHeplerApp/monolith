<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\Services;

use App\Application\Catalog\DTOs\AttributeOptionData;
use App\Application\Import\Services\AttributeOptionTranslationResolver;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributeOptionTranslationResolverTest extends TestCase
{
    #[DataProvider('translations')]
    public function test_it_resolves_translations(?string $incoming, ?string $stored, ?string $expected): void
    {
        $input = $this->option('anime', 'studios', $incoming);
        $before = $input->value->getEn();
        $resolved = new AttributeOptionTranslationResolver()->resolve([$input], [$this->option('anime', 'studios', $stored)]);

        self::assertSame($expected, $resolved[0]->value->getEn());
        self::assertSame('Sunrise', $resolved[0]->value->getRu());
        self::assertSame($before, $input->value->getEn());
    }

    public function test_it_does_not_mix_definitions_or_content_types(): void
    {
        $input = $this->option('anime', 'studios', null);
        $resolved = new AttributeOptionTranslationResolver()->resolve([$input], [
            $this->option('anime', 'genres', 'Wrong attribute'),
            $this->option('movie', 'studios', 'Wrong type'),
            new AttributeOptionData('studios', TitleContentType::ANIME, LocalizedText::create('Other', 'Wrong value')),
        ]);

        self::assertSame($input, $resolved[0]);
        self::assertSame([], new AttributeOptionTranslationResolver()->resolve([], []));
    }

    public static function translations(): iterable
    {
        yield 'incoming wins' => ['New', 'Old', 'New'];
        yield 'stored fills absent incoming' => [null, 'Old', 'Old'];
        yield 'both absent' => [null, null, null];
        yield 'only incoming' => ['New', null, 'New'];
        yield 'blank incoming is absent' => ['   ', 'Old', 'Old'];
    }


    private function option(string $type, string $code, ?string $translation): AttributeOptionData
    {
        return new AttributeOptionData($code, TitleContentType::from($type), LocalizedText::create('Sunrise', $translation));
    }
}
