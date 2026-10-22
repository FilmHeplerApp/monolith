<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Media\Enums;

use App\Application\Media\Enums\ImageVariant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImageVariantTest extends TestCase
{
    private const string INGESTION_UUID = '6f1c2a44-9c0e-4b1a-8d3e-1a2b3c4d5e6f';


    /**
     * @return array<string, array{ImageVariant, string}>
     */
    public static function objectKeys(): array
    {
        $uuid = self::INGESTION_UUID;

        return [
            'poster full' => [ImageVariant::PosterFull, "titles/posters/{$uuid}/full.webp"],
            'poster thumb' => [ImageVariant::PosterThumb, "titles/posters/{$uuid}/thumb.webp"],
            'banner' => [ImageVariant::Banner, "titles/banners/{$uuid}/banner.webp"],
            'avatar' => [ImageVariant::Avatar, "users/avatars/{$uuid}/avatar.webp"],
        ];
    }

    #[Test]
    #[DataProvider('objectKeys')]
    public function it_builds_the_object_key(ImageVariant $variant, string $expected): void
    {
        self::assertSame($expected, $variant->objectKey(self::INGESTION_UUID));
    }

    #[Test]
    public function poster_variants_share_a_directory(): void
    {
        $full = ImageVariant::PosterFull->objectKey(self::INGESTION_UUID);
        $thumb = ImageVariant::PosterThumb->objectKey(self::INGESTION_UUID);

        self::assertSame(dirname($full), dirname($thumb));
    }
}
