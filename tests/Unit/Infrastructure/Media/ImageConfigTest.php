<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Media;

use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Enums\ResizeStrategy;
use App\Infrastructure\Media\Config\ImageConfig;
use App\Infrastructure\Media\Config\ImageConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ImageConfigTest extends TestCase
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
    public function it_builds_a_variant_from_config(
        ImageVariant $variant,
        int $maxWidth,
        int $maxHeight,
        ResizeStrategy $strategy,
        int $quality,
        int $sharpen,
    ): void {
        $spec = ImageConfig::variant($variant);

        self::assertSame($maxWidth, $spec->maxWidth);
        self::assertSame($maxHeight, $spec->maxHeight);
        self::assertSame($strategy, $spec->strategy);
        self::assertSame($quality, $spec->quality);
        self::assertSame($sharpen, $spec->sharpen);
    }

    #[Test]
    public function it_exposes_the_remaining_image_settings(): void
    {
        self::assertSame('s3', ImageConfig::disk());
        self::assertSame('images', ImageConfig::queue());
        self::assertSame(15 * 1024 * 1024, ImageConfig::maxBytes());
        self::assertSame(25_000_000, ImageConfig::maxPixels());
        self::assertSame(['image/jpeg', 'image/png', 'image/webp'], ImageConfig::allowedMimeTypes());
        self::assertSame(['http', 'https'], ImageConfig::allowedSchemes());
        self::assertSame(10, ImageConfig::connectTimeout());
        self::assertSame(30, ImageConfig::timeout());
        self::assertSame('webp', ImageConfig::outputFormat());
        self::assertSame('public, max-age=31536000, immutable', ImageConfig::cacheControl());
    }

    #[Test]
    public function it_throws_when_the_variant_is_missing_from_config(): void
    {
        config(['images.variants.banner' => null]);

        $this->expectException(ImageConfigurationException::class);
        $this->expectExceptionMessage('Image variant "banner" is not configured');

        ImageConfig::variant(ImageVariant::Banner);
    }

    #[Test]
    public function it_throws_when_a_required_variant_key_is_missing(): void
    {
        config(['images.variants.avatar' => [
            'max_width' => 256,
            'max_height' => 256,
            'strategy' => ResizeStrategy::Cover->value,
            'quality' => 82,
        ]]);

        $this->expectException(ImageConfigurationException::class);
        $this->expectExceptionMessage('Image variant "avatar" is not configured');

        ImageConfig::variant(ImageVariant::Avatar);
    }

    #[Test]
    public function it_throws_on_an_unknown_strategy(): void
    {
        config(['images.variants.banner.strategy' => 'squish']);

        $this->expectException(ImageConfigurationException::class);
        $this->expectExceptionMessage('images.variants.banner.strategy');

        ImageConfig::variant(ImageVariant::Banner);
    }

    #[Test]
    public function it_throws_when_a_scalar_setting_has_the_wrong_type(): void
    {
        config(['images.input.max_bytes' => '15mb']);

        $this->expectException(ImageConfigurationException::class);
        $this->expectExceptionMessage('images.input.max_bytes');

        ImageConfig::maxBytes();
    }
}
