<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\DTOs\DownloadedImage;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Exceptions\UnsupportedImageException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MeasureImageCommandTest extends TestCase
{
    private const string URL = 'https://cdn.example/poster.jpg';


    private MockInterface $imageDownloader;


    protected function setUp(): void
    {
        parent::setUp();

        $this->imageDownloader = Mockery::mock(ImageDownloaderContract::class);
        $this->app->instance(ImageDownloaderContract::class, $this->imageDownloader);
    }


    #[Test]
    public function it_prints_source_and_compressed_size_geometry_and_ratio(): void
    {
        $bytes = $this->fixture('poster_424.jpg');
        $source = getimagesizefromstring($bytes);
        $processed = $this->app->make(ImageCompressorContract::class)->compress($bytes, ImageVariant::PosterThumb);

        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::URL)
            ->andReturn(new DownloadedImage($bytes, 'image/jpeg'));

        $this->artisan('filmhelper:measure-image', [
            'url' => self::URL,
            'variant' => ImageVariant::PosterThumb->value,
        ])->expectsOutputToContain('Variant: poster_thumb')
            ->expectsOutputToContain(sprintf(
                'Source: %d bytes, %dx%d, image/jpeg',
                strlen($bytes),
                $source[0],
                $source[1],
            ))
            ->expectsOutputToContain(sprintf(
                'Output: %d bytes, %dx%d, image/webp',
                $processed->sizeInBytes(),
                $processed->width,
                $processed->height,
            ))
            ->expectsOutputToContain(sprintf('Ratio: %.2fx', strlen($bytes) / $processed->sizeInBytes()))
            ->assertSuccessful();
    }

    #[Test]
    public function it_rejects_an_unknown_variant_without_downloading(): void
    {
        $this->imageDownloader->shouldReceive('download')->never();

        $this->artisan('filmhelper:measure-image', [
            'url' => self::URL,
            'variant' => 'poster',
        ])->expectsOutputToContain('Unknown variant. Expected one of: poster_full, poster_thumb, banner, avatar.')
            ->assertFailed();
    }

    #[Test]
    public function it_prints_a_permanent_download_failure(): void
    {
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::URL)
            ->andThrow(UnsupportedImageException::imageNotFound(self::URL));

        $this->artisan('filmhelper:measure-image', [
            'url' => self::URL,
            'variant' => ImageVariant::PosterFull->value,
        ])->expectsOutputToContain('has not been found')
            ->assertFailed();
    }


    private function fixture(string $name): string
    {
        $bytes = file_get_contents(dirname(__DIR__, 2).'/Fixtures/Media/'.$name);

        if ($bytes === false) {
            self::fail("Fixture [{$name}] is missing.");
        }

        return $bytes;
    }
}
