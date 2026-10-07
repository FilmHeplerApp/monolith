<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Media\Compression;

use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Exceptions\ImageProcessingException;
use App\Infrastructure\Media\Compression\GdImageCompressor;
use App\Infrastructure\Media\Config\ImageConfig;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\InvalidArgumentException;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GdImageCompressorTest extends TestCase
{
    private const string FIXTURE_NARROW = 'poster_177.jpg';
    private const string FIXTURE_MEDIAN = 'poster_424.jpg';
    private const string FIXTURE_LARGE = 'poster_1280.jpg';
    private const string FIXTURE_LANDSCAPE = 'poster_landscape.jpg';
    private const string FIXTURE_ICC = 'poster_icc.jpg';
    private const string FIXTURE_EXIF = 'avatar_exif.jpg';


    private GdImageCompressor $compressor;

    private ImageManager $manager;


    /**
     * @throws InvalidArgumentException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new ImageManager(new Driver());
        $this->compressor = new GdImageCompressor($this->manager);
    }


    /**
     * @return array<string, array{ImageVariant}>
     */
    public static function variants(): array
    {
        $cases = [];

        foreach (ImageVariant::cases() as $variant) {
            $cases[$variant->value] = [$variant];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('variants')]
    public function it_encodes_each_variant_as_decodable_webp(ImageVariant $variant): void
    {
        $result = $this->compress($this->fixture(self::FIXTURE_LARGE), $variant);

        self::assertSame('image/webp', $result->mimeType);
        self::assertSame('RIFF', substr($result->bytes, 0, 4));
        self::assertSame('WEBP', substr($result->bytes, 8, 4));
        self::assertSame($result->sizeInBytes(), strlen($result->bytes));

        $decoded = $this->decoded($result);

        self::assertSame($result->width, $decoded->width());
        self::assertSame($result->height, $decoded->height());
    }

    #[Test]
    #[DataProvider('variants')]
    public function it_does_not_exceed_variant_limits(ImageVariant $variant): void
    {
        $spec = ImageConfig::variant($variant);
        $result = $this->compress($this->fixture(self::FIXTURE_LARGE), $variant);

        self::assertLessThanOrEqual($spec->maxWidth, $result->width);
        self::assertLessThanOrEqual($spec->maxHeight, $result->height);
    }

    #[Test]
    public function it_does_not_upscale_a_source_smaller_than_the_target(): void
    {
        $fit = $this->compress($this->fixture(self::FIXTURE_NARROW), ImageVariant::PosterFull);
        $cover = $this->compress($this->fixture(self::FIXTURE_NARROW), ImageVariant::Avatar);

        self::assertSame(177, $fit->width);
        self::assertSame(266, $fit->height);
        self::assertSame('image/webp', $fit->mimeType);
        self::assertSame(177, $cover->width);
        self::assertSame(177, $cover->height);
    }

    #[Test]
    public function it_downscales_the_large_poster_for_full_variant(): void
    {
        $result = $this->compress($this->fixture(self::FIXTURE_LARGE), ImageVariant::PosterFull);

        self::assertSame(1080, $result->width);
        self::assertSame(1620, $result->height);
    }

    #[Test]
    public function it_keeps_aspect_ratio_for_fit_and_crops_cover_to_the_target(): void
    {
        $fit = $this->compress($this->fixture(self::FIXTURE_LANDSCAPE), ImageVariant::PosterFull);
        $cover = $this->compress($this->fixture(self::FIXTURE_LANDSCAPE), ImageVariant::Banner);

        self::assertEqualsWithDelta(1680 / 800, $fit->width / $fit->height, 0.02);
        self::assertSame(1280, $cover->width);
        self::assertSame(720, $cover->height);
    }

    #[Test]
    public function it_resizes_the_median_poster_only_for_the_thumb(): void
    {
        $full = $this->compress($this->fixture(self::FIXTURE_MEDIAN), ImageVariant::PosterFull);
        $thumb = $this->compress($this->fixture(self::FIXTURE_MEDIAN), ImageVariant::PosterThumb);

        self::assertSame(424, $full->width);
        self::assertSame(636, $full->height);
        self::assertLessThanOrEqual(360, $thumb->width);
        self::assertLessThanOrEqual(540, $thumb->height);
        self::assertTrue($thumb->width < 424 || $thumb->height < 636);
    }

    #[Test]
    public function it_shrinks_a_heavy_source(): void
    {
        $source = $this->fixture(self::FIXTURE_LARGE);
        $result = $this->compress($source, ImageVariant::PosterThumb);

        self::assertLessThan(strlen($source), $result->sizeInBytes());
        self::assertSame(360, $result->width);
        self::assertSame(540, $result->height);
    }

    #[Test]
    public function it_still_encodes_a_tiny_source_as_webp(): void
    {
        $result = $this->compress($this->fixture(self::FIXTURE_NARROW), ImageVariant::PosterThumb);

        self::assertSame('image/webp', $result->mimeType);
        self::assertSame('WEBP', substr($result->bytes, 8, 4));
    }

    #[Test]
    public function it_applies_exif_orientation_before_encoding(): void
    {
        $source = $this->fixture(self::FIXTURE_EXIF);
        $exif = exif_read_data($this->fixturePath(self::FIXTURE_EXIF));

        self::assertSame(6, $exif['Orientation'] ?? null);
        self::assertSame('N', $exif['GPSLatitudeRef'] ?? null);

        $result = $this->compress($source, ImageVariant::PosterFull);
        $color = $this->decoded($result)->colorAt(20, 5)->toHex();

        self::assertSame(40, $result->width);
        self::assertSame(80, $result->height);
        self::assertGreaterThan(hexdec(substr($color, 4, 2)), hexdec(substr($color, 0, 2)));
    }

    #[Test]
    public function it_strips_exif_icc_and_xmp_from_the_output(): void
    {
        $exifResult = $this->compress($this->fixture(self::FIXTURE_EXIF), ImageVariant::Avatar);
        $iccResult = $this->compress($this->fixture(self::FIXTURE_ICC), ImageVariant::PosterFull);

        self::assertFalse(@exif_read_data('data://image/webp;base64,' . base64_encode($exifResult->bytes)));
        self::assertStringNotContainsString('EXIF', $exifResult->bytes);
        self::assertStringNotContainsString('XMP ', $exifResult->bytes);
        self::assertStringNotContainsString('ICCP', $iccResult->bytes);
        self::assertStringNotContainsString('ICC_PROFILE', $iccResult->bytes);
    }

    #[Test]
    public function it_keeps_png_transparency_instead_of_a_black_background(): void
    {
        $result = $this->compress($this->transparentPng(), ImageVariant::Avatar);
        $corner = $this->decoded($result)->colorAt(0, 0);

        self::assertSame('image/webp', $result->mimeType);
        self::assertTrue($corner->isTransparent() || $corner->isClear());
    }

    #[Test]
    public function it_rejects_undecodable_bytes(): void
    {
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('Image could not be decoded');

        $this->compress('not-an-image', ImageVariant::PosterFull);
    }

    #[Test]
    public function it_rejects_empty_bytes(): void
    {
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('Image could not be decoded');

        $this->compress('', ImageVariant::PosterFull);
    }

    #[Test]
    public function it_accepts_webp_and_png_sources(): void
    {
        $webp = $this->compress($this->fixture(self::FIXTURE_NARROW), ImageVariant::PosterThumb);
        $fromWebp = $this->compress($webp->bytes, ImageVariant::PosterThumb);
        $fromPng = $this->compress($this->transparentPng(), ImageVariant::PosterFull);

        self::assertSame('image/webp', $fromWebp->mimeType);
        self::assertSame($webp->width, $fromWebp->width);
        self::assertSame($webp->height, $fromWebp->height);
        self::assertSame(64, $fromPng->width);
        self::assertSame(64, $fromPng->height);
        self::assertSame('image/webp', $fromPng->mimeType);
    }

    #[Test]
    public function it_is_bound_in_the_container(): void
    {
        $resolved = $this->app->make(ImageCompressorContract::class);

        self::assertInstanceOf(GdImageCompressor::class, $resolved);
    }


    private function compress(string $contents, ImageVariant $variant): ProcessedImage
    {
        return $this->compressor->compress($contents, ImageConfig::variant($variant));
    }

    private function decoded(ProcessedImage $result): ImageInterface
    {
        return $this->manager->decodeBinary($result->bytes);
    }

    private function fixture(string $name): string
    {
        $bytes = file_get_contents($this->fixturePath($name));

        if ($bytes === false) {
            self::fail("Fixture [{$name}] is missing.");
        }

        return $bytes;
    }

    private function fixturePath(string $name): string
    {
        return dirname(__DIR__, 4) . '/Fixtures/Media/' . $name;
    }

    private function transparentPng(): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, 63, 63, $transparent);

        $red = imagecolorallocatealpha($image, 255, 0, 0, 0);
        imagefilledrectangle($image, 16, 16, 47, 47, $red);

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
