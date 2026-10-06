<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Media\DTOs;

use App\Application\Media\DTOs\ImageVariantSpec;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Enums\ResizeStrategy;
use App\Application\Media\Exceptions\ImageProcessingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ValueError;

final class ImageVariantSpecTest extends TestCase
{
    /**
     * @return array<string, array{ImageVariant, int, int, ResizeStrategy, int, int}>
     */
    public static function configuredVariants(): array
    {
        return [
            'poster_full' => [ImageVariant::PosterFull, 1080, 1620, ResizeStrategy::Fit, 82, 5],
            'poster_thumb' => [ImageVariant::PosterThumb, 360, 540, ResizeStrategy::Fit, 78, 8],
            'banner' => [ImageVariant::Banner, 1280, 720, ResizeStrategy::Cover, 80, 0],
            'avatar' => [ImageVariant::Avatar, 256, 256, ResizeStrategy::Cover, 82, 0],
        ];
    }

    #[Test]
    #[DataProvider('configuredVariants')]
    public function it_builds_from_config(
        ImageVariant $variant,
        int $maxWidth,
        int $maxHeight,
        ResizeStrategy $strategy,
        int $quality,
        int $sharpen,
    ): void {
        $spec = ImageVariantSpec::fromVariant($variant);

        self::assertSame($maxWidth, $spec->maxWidth);
        self::assertSame($maxHeight, $spec->maxHeight);
        self::assertSame($strategy, $spec->strategy);
        self::assertSame($quality, $spec->quality);
        self::assertSame($sharpen, $spec->sharpen);
    }

    #[Test]
    public function it_throws_when_the_variant_is_missing_from_config(): void
    {
        config(['images.variants.banner' => null]);

        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('Image variant "banner" is not configured');

        ImageVariantSpec::fromVariant(ImageVariant::Banner);
    }

    #[Test]
    public function it_throws_when_a_required_key_is_missing(): void
    {
        config(['images.variants.avatar' => [
            'max_width' => 256,
            'max_height' => 256,
            'strategy' => ResizeStrategy::Cover->value,
            'quality' => 82,
        ]]);

        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('Image variant "avatar" is not configured');

        ImageVariantSpec::fromVariant(ImageVariant::Avatar);
    }

    #[Test]
    public function it_throws_on_an_unknown_strategy(): void
    {
        config(['images.variants.banner.strategy' => 'squish']);

        $this->expectException(ValueError::class);

        ImageVariantSpec::fromVariant(ImageVariant::Banner);
    }
}
