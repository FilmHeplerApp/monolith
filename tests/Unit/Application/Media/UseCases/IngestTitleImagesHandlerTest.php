<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Media\UseCases;

use App\Application\Catalog\Contracts\TitleRepositoryContract;
use App\Application\Catalog\DTOs\TitleImageData;
use App\Application\Catalog\Exceptions\TitleNotFoundException;
use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\DTOs\DownloadedImage;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\DTOs\StoredImage;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\ImageProcessingException;
use App\Application\Media\Exceptions\ImageStorageException;
use App\Application\Media\Exceptions\UnsupportedImageException;
use App\Application\Media\UseCases\IngestTitleImagesHandler;
use App\Application\Media\UseCases\StoreRemoteImageHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;
use Throwable;

final class IngestTitleImagesHandlerTest extends TestCase
{
    private const string CANONICAL_KEY = 'naruto|anime';
    private const string INGESTION_UUID = '6f1c2a44-9c0e-4b1a-8d3e-1a2b3c4d5e6f';
    private const string POSTER_URL = 'https://cdn.example/poster.jpg';
    private const string BANNER_URL = 'https://cdn.example/banner.jpg';
    private const string POSTER_RAW = 'poster-bytes';
    private const string BANNER_RAW = 'banner-bytes';
    private const string OLD_POSTER_KEY = 'titles/posters/old-uuid/full.webp';
    private const string OLD_POSTER_THUMB_KEY = 'titles/posters/old-uuid/thumb.webp';
    private const string OLD_BANNER_KEY = 'titles/banners/old-uuid/banner.webp';
    private const string STALE_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';


    private MockInterface $imageCompressor;
    private MockInterface $titleRepository;
    private MockInterface $imageDownloader;
    private MockInterface $imageStorage;


    protected function setUp(): void
    {
        parent::setUp();

        Str::createUuidsUsing(static fn() => Uuid::fromString(self::INGESTION_UUID));

        $this->imageCompressor = Mockery::mock(ImageCompressorContract::class);
        $this->titleRepository = Mockery::mock(TitleRepositoryContract::class);
        $this->imageDownloader = Mockery::mock(ImageDownloaderContract::class);
        $this->imageStorage = Mockery::mock(ImageStorageContract::class);
    }


    #[Test]
    public function it_throws_when_the_title_is_missing(): void
    {
        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturnNull();
        $this->imageDownloader->shouldReceive('download')->never();

        $this->expectException(TitleNotFoundException::class);
        $this->expectExceptionMessage('Title was not found for canonical key "naruto|anime".');

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
    }

    #[Test]
    public function it_ingests_poster_and_banner_and_deletes_old_prefixes(): void
    {
        $this->givenTitle($this->staleImageData());
        $this->expectPosterDownload();
        $this->expectPosterStored();
        $this->expectBannerDownload();
        $this->expectBannerStored();

        $this->titleRepository->shouldReceive('savePoster')
            ->once()
            ->with(
                self::CANONICAL_KEY,
                $this->posterFullKey(),
                $this->posterThumbKey(),
                hash('sha256', self::POSTER_URL),
            );
        $this->titleRepository->shouldReceive('saveBanner')
            ->once()
            ->with(
                self::CANONICAL_KEY,
                $this->bannerKey(),
                hash('sha256', self::BANNER_URL),
            );
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/posters/old-uuid');
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/banners/old-uuid');

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
    }

    #[Test]
    public function it_skips_old_poster_prefix_delete_on_first_ingest(): void
    {
        $this->givenTitle(new TitleImageData(null, null, null, null, null));
        $this->expectPosterDownload();
        $this->expectPosterStored();
        $this->titleRepository->shouldReceive('savePoster')->once();
        $this->imageStorage->shouldReceive('deleteByPrefix')->never();

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, null);
    }

    #[Test]
    public function it_skips_old_banner_prefix_delete_on_first_ingest(): void
    {
        $this->givenTitle(new TitleImageData(
            posterKey: self::OLD_POSTER_KEY,
            posterThumbKey: self::OLD_POSTER_THUMB_KEY,
            bannerKey: null,
            posterSourceHash: hash('sha256', self::POSTER_URL),
            bannerSourceHash: null,
        ));
        $this->expectBannerDownload();
        $this->expectBannerStored();
        $this->titleRepository->shouldReceive('savePoster')->never();
        $this->titleRepository->shouldReceive('saveBanner')->once();
        $this->imageStorage->shouldReceive('deleteByPrefix')->never();

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
    }

    #[Test]
    public function it_skips_poster_when_the_source_hash_matches(): void
    {
        $this->givenTitle($this->staleImageData(
            posterSourceHash: hash('sha256', self::POSTER_URL),
        ));
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::BANNER_URL)
            ->andReturn(new DownloadedImage(self::BANNER_RAW, 'image/jpeg'));
        $this->expectBannerStored();
        $this->titleRepository->shouldReceive('savePoster')->never();
        $this->titleRepository->shouldReceive('saveBanner')->once();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/banners/old-uuid');
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->never()
            ->with('titles/posters/old-uuid');

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
    }

    #[Test]
    public function it_skips_banner_when_the_url_is_null(): void
    {
        $this->givenTitle($this->staleImageData());
        $this->expectPosterDownload();
        $this->expectPosterStored();
        $this->titleRepository->shouldReceive('savePoster')->once();
        $this->titleRepository->shouldReceive('saveBanner')->never();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/posters/old-uuid');
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->with('titles/banners/old-uuid')
            ->never();

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, null);
    }

    #[Test]
    public function it_skips_banner_when_the_source_hash_matches(): void
    {
        $this->givenTitle($this->staleImageData(
            bannerSourceHash: hash('sha256', self::BANNER_URL),
        ));
        $this->expectPosterDownload();
        $this->expectPosterStored();
        $this->titleRepository->shouldReceive('savePoster')->once();
        $this->titleRepository->shouldReceive('saveBanner')->never();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/posters/old-uuid');
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->with('titles/banners/old-uuid')
            ->never();

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
    }

    #[Test]
    public function it_rethrows_poster_download_failure_after_ingesting_banner(): void
    {
        $downloadException = new ImageDownloadException('timeout');

        $this->givenTitle($this->staleImageData());
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andThrow($downloadException);
        $this->expectBannerDownload();
        $this->expectBannerStored();
        $this->titleRepository->shouldReceive('savePoster')->never();
        $this->titleRepository->shouldReceive('saveBanner')->once();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/banners/old-uuid');
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->with('titles/posters/old-uuid')
            ->never();

        try {
            $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
            self::fail('Expected ImageDownloadException.');
        } catch (ImageDownloadException $exception) {
            self::assertSame($downloadException, $exception);
        }
    }

    #[Test]
    public function it_logs_the_second_retryable_failure_and_rethrows_the_first(): void
    {
        $posterException = new ImageDownloadException('poster timeout');
        $bannerException = new ImageDownloadException('banner timeout');

        $this->givenTitle($this->staleImageData());
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andThrow($posterException);
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::BANNER_URL)
            ->andThrow($bannerException);
        $this->imageStorage->shouldReceive('deleteByPrefix')->never();
        $this->expectWarning($bannerException, 'banner', self::BANNER_URL);

        try {
            $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
            self::fail('Expected ImageDownloadException.');
        } catch (ImageDownloadException $exception) {
            self::assertSame($posterException, $exception);
        }
    }

    #[Test]
    public function it_logs_a_permanent_poster_failure_and_continues_with_banner(): void
    {
        $notFound = UnsupportedImageException::imageNotFound(self::POSTER_URL);

        $this->givenTitle($this->staleImageData());
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andThrow($notFound);
        $this->expectBannerDownload();
        $this->expectBannerStored();
        $this->titleRepository->shouldReceive('savePoster')->never();
        $this->titleRepository->shouldReceive('saveBanner')->once();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/banners/old-uuid');
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->with('titles/posters/old-uuid')
            ->never();
        $this->expectWarning($notFound, 'poster', self::POSTER_URL);

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
    }

    #[Test]
    public function it_deletes_the_current_poster_prefix_when_thumb_storage_fails(): void
    {
        $storageException = ImageStorageException::storingFailed($this->posterThumbKey());

        $this->givenTitle($this->staleImageData());
        $this->expectPosterDownload();
        $this->expectPosterFullStoredThenThumbFails($storageException);
        $this->expectBannerDownload();
        $this->expectBannerStored();
        $this->titleRepository->shouldReceive('savePoster')->never();
        $this->titleRepository->shouldReceive('saveBanner')->once();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/posters/' . self::INGESTION_UUID);
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/banners/old-uuid');
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->with('titles/posters/old-uuid')
            ->never();

        try {
            $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
            self::fail('Expected ImageStorageException.');
        } catch (ImageStorageException $exception) {
            self::assertSame($storageException, $exception);
        }
    }

    #[Test]
    public function it_deletes_the_current_poster_prefix_when_thumb_processing_fails(): void
    {
        $processingException = ImageProcessingException::encodeFailed('webp', 'gd');

        $this->givenTitle($this->staleImageData());
        $this->expectPosterDownload();
        $this->expectPosterFullStoredThenThumbFails($processingException);
        $this->expectBannerDownload();
        $this->expectBannerStored();
        $this->titleRepository->shouldReceive('savePoster')->never();
        $this->titleRepository->shouldReceive('saveBanner')->once();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/posters/' . self::INGESTION_UUID);
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/banners/old-uuid');
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->with('titles/posters/old-uuid')
            ->never();
        $this->expectWarning($processingException, 'poster', self::POSTER_URL);

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
    }

    #[Test]
    public function it_does_not_fail_when_old_prefix_delete_fails(): void
    {
        $deleteException = ImageStorageException::deletingFailed('titles/posters/old-uuid');

        $this->givenTitle($this->staleImageData());
        $this->expectPosterDownload();
        $this->expectPosterStored();
        $this->titleRepository->shouldReceive('savePoster')->once();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/posters/old-uuid')
            ->andThrow($deleteException);
        $this->expectWarning($deleteException, 'poster', self::POSTER_URL);

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, null);
    }

    #[Test]
    public function it_keeps_the_original_storage_error_when_current_prefix_delete_fails(): void
    {
        $storageException = ImageStorageException::storingFailed($this->posterThumbKey());
        $deleteException = ImageStorageException::deletingFailed('titles/posters/' . self::INGESTION_UUID);

        $this->givenTitle($this->staleImageData());
        $this->expectPosterDownload();
        $this->expectPosterFullStoredThenThumbFails($storageException);
        $this->expectBannerDownload();
        $this->expectBannerStored();
        $this->titleRepository->shouldReceive('saveBanner')->once();
        $this->titleRepository->shouldReceive('savePoster')->never();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/posters/' . self::INGESTION_UUID)
            ->andThrow($deleteException);
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/banners/old-uuid');
        $this->expectWarning($deleteException, 'poster', self::POSTER_URL);

        try {
            $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
            self::fail('Expected ImageStorageException.');
        } catch (ImageStorageException $exception) {
            self::assertSame($storageException, $exception);
        }
    }

    #[Test]
    public function it_rethrows_banner_failure_when_poster_succeeded(): void
    {
        $bannerException = new ImageDownloadException('banner timeout');

        $this->givenTitle($this->staleImageData());
        $this->expectPosterDownload();
        $this->expectPosterStored();
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::BANNER_URL)
            ->andThrow($bannerException);
        $this->titleRepository->shouldReceive('savePoster')->once();
        $this->titleRepository->shouldReceive('saveBanner')->never();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/posters/old-uuid');
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->with('titles/banners/old-uuid')
            ->never();

        try {
            $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
            self::fail('Expected ImageDownloadException.');
        } catch (ImageDownloadException $exception) {
            self::assertSame($bannerException, $exception);
        }
    }

    #[Test]
    public function it_logs_a_permanent_banner_failure_without_failing_the_handler(): void
    {
        $notFound = UnsupportedImageException::imageNotFound(self::BANNER_URL);

        $this->givenTitle($this->staleImageData());
        $this->expectPosterDownload();
        $this->expectPosterStored();
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::BANNER_URL)
            ->andThrow($notFound);
        $this->titleRepository->shouldReceive('savePoster')->once();
        $this->titleRepository->shouldReceive('saveBanner')->never();
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->once()
            ->with('titles/posters/old-uuid');
        $this->imageStorage->shouldReceive('deleteByPrefix')
            ->with('titles/banners/old-uuid')
            ->never();
        $this->expectWarning($notFound, 'banner', self::BANNER_URL);

        $this->handler()->handle(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);
    }


    protected function tearDown(): void
    {
        Str::createUuidsNormally();

        parent::tearDown();
    }


    private function handler(): IngestTitleImagesHandler
    {
        return new IngestTitleImagesHandler(
            new StoreRemoteImageHandler($this->imageCompressor, $this->imageStorage),
            $this->titleRepository,
            $this->imageDownloader,
            $this->imageStorage,
        );
    }

    private function givenTitle(TitleImageData $titleImageData): void
    {
        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturn($titleImageData);
    }

    private function staleImageData(
        ?string $posterSourceHash = self::STALE_HASH,
        ?string $bannerSourceHash = self::STALE_HASH,
    ): TitleImageData {
        return new TitleImageData(
            posterKey: self::OLD_POSTER_KEY,
            posterThumbKey: self::OLD_POSTER_THUMB_KEY,
            bannerKey: self::OLD_BANNER_KEY,
            posterSourceHash: $posterSourceHash,
            bannerSourceHash: $bannerSourceHash,
        );
    }

    private function expectPosterDownload(): void
    {
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andReturn(new DownloadedImage(self::POSTER_RAW, 'image/jpeg'));
    }

    private function expectBannerDownload(): void
    {
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::BANNER_URL)
            ->andReturn(new DownloadedImage(self::BANNER_RAW, 'image/jpeg'));
    }

    private function expectPosterStored(): void
    {
        $this->expectVariantStored(self::POSTER_RAW, ImageVariant::PosterFull);
        $this->expectVariantStored(self::POSTER_RAW, ImageVariant::PosterThumb);
    }

    private function expectBannerStored(): void
    {
        $this->expectVariantStored(self::BANNER_RAW, ImageVariant::Banner);
    }

    private function expectPosterFullStoredThenThumbFails(Throwable $exception): void
    {
        $this->expectVariantStored(self::POSTER_RAW, ImageVariant::PosterFull);

        if ($exception instanceof ImageProcessingException) {
            $this->imageCompressor->shouldReceive('compress')
                ->once()
                ->with(self::POSTER_RAW, ImageVariant::PosterThumb)
                ->andThrow($exception);

            return;
        }

        $processed = $this->processedImage();
        $this->imageCompressor->shouldReceive('compress')
            ->once()
            ->with(self::POSTER_RAW, ImageVariant::PosterThumb)
            ->andReturn($processed);
        $this->imageStorage->shouldReceive('put')
            ->once()
            ->with($processed->bytes, $this->posterThumbKey(), $processed->mimeType)
            ->andThrow($exception);
    }

    private function expectVariantStored(string $raw, ImageVariant $variant): void
    {
        $processed = $this->processedImage();
        $key = $variant->objectKey(self::INGESTION_UUID);

        $this->imageCompressor->shouldReceive('compress')
            ->once()
            ->with($raw, $variant)
            ->andReturn($processed);
        $this->imageStorage->shouldReceive('put')
            ->once()
            ->with($processed->bytes, $key, $processed->mimeType)
            ->andReturn(new StoredImage($key));
    }

    private function processedImage(): ProcessedImage
    {
        return new ProcessedImage('webp-bytes', 'image/webp', 360, 540);
    }

    private function expectWarning(Throwable $exception, string $variant, string $url): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                $exception->getMessage(),
                [
                    'title_canonical_key' => self::CANONICAL_KEY,
                    'variant' => $variant,
                    'external_url' => $url,
                ],
            );
    }

    private function posterFullKey(): string
    {
        return ImageVariant::PosterFull->objectKey(self::INGESTION_UUID);
    }

    private function posterThumbKey(): string
    {
        return ImageVariant::PosterThumb->objectKey(self::INGESTION_UUID);
    }

    private function bannerKey(): string
    {
        return ImageVariant::Banner->objectKey(self::INGESTION_UUID);
    }
}
