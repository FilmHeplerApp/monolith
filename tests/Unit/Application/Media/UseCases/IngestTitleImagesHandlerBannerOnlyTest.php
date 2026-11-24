<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Media\UseCases;

use App\Application\Catalog\Contracts\TitleRepositoryContract;
use App\Application\Catalog\DTOs\TitleImageData;
use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\DTOs\DownloadedImage;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\DTOs\StoredImage;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\UseCases\IngestTitleImagesHandler;
use App\Application\Media\UseCases\StoreRemoteImageHandler;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IngestTitleImagesHandlerBannerOnlyTest extends TestCase
{
    private const string CANONICAL_KEY = 'naruto|anime';
    private const string BANNER_URL = 'https://cdn.example/banner.jpg';
    private const string OLD_BANNER_KEY = 'titles/banners/old-uuid/banner.webp';


    private MockInterface $titles;
    private MockInterface $downloader;
    private MockInterface $compressor;
    private MockInterface $storage;


    #[Test]
    public function it_skips_the_poster_without_downloading_when_the_poster_url_is_null(): void
    {
        $this->titles->shouldReceive('findImageData')->once()->andReturn(new TitleImageData(
            'titles/posters/old/full.webp',
            'titles/posters/old/thumb.webp',
            null,
            null,
            hash('sha256', self::BANNER_URL),
        ));
        $this->downloader->shouldReceive('download')->never();
        $this->titles->shouldReceive('savePoster')->never();
        $this->titles->shouldReceive('saveBanner')->never();
        $this->storage->shouldReceive('deleteByPrefix')->never();

        $this->handler()->handle(self::CANONICAL_KEY, null, self::BANNER_URL);
    }

    #[Test]
    public function it_stores_the_banner_when_only_the_banner_url_is_given(): void
    {
        $this->titles->shouldReceive('findImageData')->once()->andReturn(new TitleImageData(null, null, self::OLD_BANNER_KEY, null, 'stale'));
        $this->downloader->shouldReceive('download')->once()->with(self::BANNER_URL)->andReturn(new DownloadedImage('banner-bytes', 'image/jpeg'));
        $this->compressor->shouldReceive('compress')->once()->with('banner-bytes', ImageVariant::Banner)
            ->andReturn(new ProcessedImage('webp', 'image/webp', 1280, 720));
        $this->storage->shouldReceive('put')->once()->andReturnUsing(static fn (string $bytes, string $key): StoredImage => new StoredImage($key));
        $this->titles->shouldReceive('savePoster')->never();
        $this->titles->shouldReceive('saveBanner')->once()->withArgs(
            static fn (string $key, string $bannerKey, string $hash): bool => $key === self::CANONICAL_KEY
                && str_starts_with($bannerKey, 'titles/banners/')
                && $hash === hash('sha256', self::BANNER_URL),
        );
        $this->storage->shouldReceive('deleteByPrefix')->once()->with('titles/banners/old-uuid');

        $this->handler()->handle(self::CANONICAL_KEY, null, self::BANNER_URL);
    }


    protected function setUp(): void
    {
        parent::setUp();

        $this->titles = Mockery::mock(TitleRepositoryContract::class);
        $this->downloader = Mockery::mock(ImageDownloaderContract::class);
        $this->compressor = Mockery::mock(ImageCompressorContract::class);
        $this->storage = Mockery::mock(ImageStorageContract::class);
    }


    private function handler(): IngestTitleImagesHandler
    {
        return new IngestTitleImagesHandler(
            new StoreRemoteImageHandler($this->compressor, $this->storage),
            $this->titles,
            $this->downloader,
            $this->storage,
        );
    }
}
