<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Media\Compression;

use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\DTOs\ImageVariantSpec;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\Enums\ImageVariant;
use App\Infrastructure\Media\Compression\ImageCompressor;
use App\Infrastructure\Media\Compression\SpecImageCompressor;
use App\Infrastructure\Media\Config\ImageConfig;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ImageCompressorTest extends TestCase
{
    #[Test]
    public function it_resolves_the_variant_spec_before_compression(): void
    {
        $variant = ImageVariant::PosterThumb;
        $spec = ImageConfig::variant($variant);
        $processed = new ProcessedImage('webp-bytes', 'image/webp', 360, 540);
        $algorithm = Mockery::mock(SpecImageCompressor::class);
        $algorithm->shouldReceive('compress')
            ->once()
            ->withArgs(function (string $contents, ImageVariantSpec $passed) use ($spec): bool {
                return $contents === 'source-bytes'
                    && $passed->maxWidth === $spec->maxWidth
                    && $passed->maxHeight === $spec->maxHeight
                    && $passed->strategy === $spec->strategy
                    && $passed->quality === $spec->quality
                    && $passed->sharpen === $spec->sharpen;
            })
            ->andReturn($processed);

        $result = (new ImageCompressor($algorithm))->compress('source-bytes', $variant);

        self::assertSame($processed, $result);
    }

    #[Test]
    public function it_is_bound_in_the_container(): void
    {
        $resolved = $this->app->make(ImageCompressorContract::class);

        self::assertInstanceOf(ImageCompressor::class, $resolved);
    }
}
