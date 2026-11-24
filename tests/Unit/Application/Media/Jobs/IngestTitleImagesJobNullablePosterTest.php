<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Media\Jobs;

use App\Application\Catalog\Contracts\TitleRepositoryContract;
use App\Application\Catalog\DTOs\TitleImageData;
use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Jobs\IngestTitleImagesJob;
use App\Application\Media\UseCases\IngestTitleImagesHandler;
use App\Application\Media\UseCases\StoreRemoteImageHandler;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IngestTitleImagesJobNullablePosterTest extends TestCase
{
    private const string CANONICAL_KEY = 'naruto|anime';
    private const string BANNER_URL = 'https://cdn.example/banner.jpg';


    #[Test]
    public function it_queues_a_banner_only_job(): void
    {
        Queue::fake();

        IngestTitleImagesJob::dispatch(self::CANONICAL_KEY, null, self::BANNER_URL);

        Queue::assertPushedOn(config('images.queue'), IngestTitleImagesJob::class, static fn (IngestTitleImagesJob $job): bool => $job->posterUrl === null
            && $job->bannerUrl === self::BANNER_URL);
    }

    #[Test]
    public function it_rejects_a_job_without_any_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Title image ingestion needs a poster or a banner URL.');

        new IngestTitleImagesJob(self::CANONICAL_KEY, null, null);
    }

    #[Test]
    public function it_carries_a_null_poster_into_the_follow_up_job(): void
    {
        Queue::fake();
        $titles = Mockery::mock(TitleRepositoryContract::class);
        $titles->shouldReceive('findImageData')->once()->andReturn(new TitleImageData(null, null, null, null, null));
        $downloader = Mockery::mock(ImageDownloaderContract::class);
        $downloader->shouldReceive('download')->once()->with(self::BANNER_URL)->andThrow(ImageDownloadException::defaultRetry('timeout', 45));
        $storage = Mockery::mock(ImageStorageContract::class);
        $handler = new IngestTitleImagesHandler(
            new StoreRemoteImageHandler(Mockery::mock(ImageCompressorContract::class), $storage),
            $titles,
            $downloader,
            $storage,
        );

        $job = unserialize(serialize(new IngestTitleImagesJob(self::CANONICAL_KEY, null, self::BANNER_URL)));
        $job->handle($handler);

        Queue::assertPushedOn(config('images.queue'), IngestTitleImagesJob::class, static fn (IngestTitleImagesJob $followUp): bool => $followUp->posterUrl === null
            && $followUp->bannerUrl === self::BANNER_URL
            && $followUp->remainingAttempts === 2
            && $followUp->delay === 45);
    }
}
