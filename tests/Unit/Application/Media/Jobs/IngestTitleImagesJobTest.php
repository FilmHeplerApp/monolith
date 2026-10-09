<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Media\Jobs;

use App\Application\Catalog\Contracts\TitleRepositoryContract;
use App\Application\Catalog\DTOs\TitleImageData;
use App\Application\Catalog\Exceptions\TitleNotFoundException;
use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Jobs\IngestTitleImagesJob;
use App\Application\Media\UseCases\IngestTitleImagesHandler;
use App\Application\Media\UseCases\StoreRemoteImageHandler;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IngestTitleImagesJobTest extends TestCase
{
    private const string CANONICAL_KEY = 'naruto|anime';

    private const string POSTER_URL = 'https://cdn.example/poster.jpg';

    private const string BANNER_URL = 'https://cdn.example/banner.jpg';


    private MockInterface $imageCompressor;

    private MockInterface $titleRepository;

    private MockInterface $imageDownloader;

    private MockInterface $imageStorage;


    protected function setUp(): void
    {
        parent::setUp();

        $this->imageCompressor = Mockery::mock(ImageCompressorContract::class);
        $this->titleRepository = Mockery::mock(TitleRepositoryContract::class);
        $this->imageDownloader = Mockery::mock(ImageDownloaderContract::class);
        $this->imageStorage = Mockery::mock(ImageStorageContract::class);
    }


    #[Test]
    public function it_queues_the_title_urls_on_the_images_queue(): void
    {
        Queue::fake();

        IngestTitleImagesJob::dispatch(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);

        Queue::assertPushedOn(config('images.queue'), IngestTitleImagesJob::class, function (IngestTitleImagesJob $job): bool {
            return $job->canonicalKey === self::CANONICAL_KEY
                && $job->posterUrl === self::POSTER_URL
                && $job->bannerUrl === self::BANNER_URL
                && $job->tries === 3
                && $job->timeout === 180;
        });
    }

    #[Test]
    public function it_queues_a_job_without_a_banner(): void
    {
        Queue::fake();

        IngestTitleImagesJob::dispatch(self::CANONICAL_KEY, self::POSTER_URL, null);

        Queue::assertPushedOn(config('images.queue'), IngestTitleImagesJob::class, function (IngestTitleImagesJob $job): bool {
            return $job->bannerUrl === null
                && $job->posterUrl === self::POSTER_URL;
        });
    }

    #[Test]
    public function it_passes_the_payload_to_the_handler(): void
    {
        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturn(new TitleImageData(
                posterKey: 'titles/posters/old-uuid/full.webp',
                posterThumbKey: 'titles/posters/old-uuid/thumb.webp',
                bannerKey: 'titles/banners/old-uuid/banner.webp',
                posterSourceHash: hash('sha256', self::POSTER_URL),
                bannerSourceHash: hash('sha256', self::BANNER_URL),
            ));
        $this->imageDownloader->shouldReceive('download')->never();
        $this->imageStorage->shouldReceive('deleteByPrefix')->never();

        $job = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);

        $job->handle($this->handler());
    }

    #[Test]
    public function it_lets_a_retryable_handler_exception_escape(): void
    {
        $downloadException = new ImageDownloadException('timeout');

        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturn(new TitleImageData(null, null, null, null, null));
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andThrow($downloadException);

        $job = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, null);

        try {
            $job->handle($this->handler());
            self::fail('Expected ImageDownloadException.');
        } catch (ImageDownloadException $exception) {
            self::assertSame($downloadException, $exception);
        }
    }

    #[Test]
    public function it_lets_a_missing_title_escape(): void
    {
        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturnNull();
        $this->imageDownloader->shouldReceive('download')->never();

        $job = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, null);

        $this->expectException(TitleNotFoundException::class);
        $this->expectExceptionMessage('Title was not found for canonical key "naruto|anime".');

        $job->handle($this->handler());
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
}
