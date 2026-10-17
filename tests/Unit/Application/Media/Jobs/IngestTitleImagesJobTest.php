<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Media\Jobs;

use App\Application\Catalog\Contracts\TitleRepositoryContract;
use App\Application\Catalog\DTOs\TitleImageData;
use App\Application\Catalog\Exceptions\TitleNotFoundException;
use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\DTOs\DownloadedImage;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\ImageStorageException;
use App\Application\Media\Jobs\IngestTitleImagesJob;
use App\Application\Media\UseCases\IngestTitleImagesHandler;
use App\Application\Media\UseCases\StoreRemoteImageHandler;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\Jobs\FakeJob;
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


    #[Test]
    public function it_queues_the_title_urls_on_the_images_queue(): void
    {
        Queue::fake();

        IngestTitleImagesJob::dispatch(self::CANONICAL_KEY, self::POSTER_URL, self::BANNER_URL);

        Queue::assertPushedOn(config('images.queue'), IngestTitleImagesJob::class, function (IngestTitleImagesJob $job): bool {
            return $job->canonicalKey === self::CANONICAL_KEY
                && $job->posterUrl === self::POSTER_URL
                && $job->bannerUrl === self::BANNER_URL
                && $job->tries === 30
                && $job->timeout === 180
                && $job->remainingAttempts === 3;
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
    public function it_dispatches_a_follow_up_job_for_the_default_retry_delay(): void
    {
        Queue::fake();

        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturn(new TitleImageData(null, null, null, null, null));
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andThrow(ImageDownloadException::defaultRetry('timeout', 45));

        $job = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, null);

        $job->handle($this->handler());

        Queue::assertPushedOn(config('images.queue'), IngestTitleImagesJob::class, function (IngestTitleImagesJob $followUp): bool {
            return $followUp->canonicalKey === self::CANONICAL_KEY
                && $followUp->posterUrl === self::POSTER_URL
                && $followUp->bannerUrl === null
                && $followUp->remainingAttempts === 2
                && $followUp->delay === 45;
        });
    }

    #[Test]
    public function it_dispatches_a_follow_up_job_for_the_retry_after_delay(): void
    {
        Queue::fake();

        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturn(new TitleImageData(null, null, null, null, null));
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andThrow(ImageDownloadException::retryAfter(429, 120));

        $job = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, null);

        $job->handle($this->handler());

        Queue::assertPushedOn(config('images.queue'), IngestTitleImagesJob::class, function (IngestTitleImagesJob $followUp): bool {
            return $followUp->remainingAttempts === 2
                && $followUp->delay === 120;
        });
    }

    #[Test]
    public function it_fails_a_rate_limit_on_the_last_download_attempt(): void
    {
        Queue::fake();

        $downloadException = ImageDownloadException::retryAfter(429, 120);

        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturn(new TitleImageData(null, null, null, null, null));
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andThrow($downloadException);

        $job = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, null);
        $job->remainingAttempts = 1;

        try {
            $job->handle($this->handler());
            self::fail('The last attempt must keep the download failure.');
        } catch (ImageDownloadException $exception) {
            self::assertSame($downloadException, $exception);
        }

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_releases_a_second_job_while_the_same_title_is_in_progress(): void
    {
        $secondQueueJob = new FakeJob;
        $secondJob = new IngestTitleImagesJob(self::CANONICAL_KEY, 'https://cdn.example/poster-new.jpg', null);
        $secondJob->setJob($secondQueueJob);

        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturn(new TitleImageData(null, null, null, null, null));
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andReturnUsing(function () use ($secondJob): void {
                $this->runThroughMiddleware($secondJob);

                throw new ImageDownloadException('still downloading');
            });

        $firstJob = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, null);

        try {
            $this->runThroughMiddleware($firstJob);
            self::fail('The first download is still in progress.');
        } catch (ImageDownloadException $exception) {
            self::assertSame('still downloading', $exception->getMessage());
            self::assertTrue($secondQueueJob->isReleased());
            self::assertSame(180, $secondQueueJob->releaseDelay);
        }
    }

    #[Test]
    public function it_does_not_spend_download_attempts_on_repeated_lock_conflicts(): void
    {
        $waitingJobs = [];

        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturn(new TitleImageData(null, null, null, null, null));
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andReturnUsing(function () use (&$waitingJobs): void {
                for ($conflict = 0; $conflict < 3; $conflict++) {
                    $waiting = new IngestTitleImagesJob(self::CANONICAL_KEY, 'https://cdn.example/poster-new.jpg', null);
                    $fake = new FakeJob;
                    $waiting->setJob($fake);
                    $this->runThroughMiddleware($waiting);
                    $waitingJobs[] = [$waiting, $fake];
                }

                throw new ImageDownloadException('still downloading');
            });

        $firstJob = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, null);

        try {
            $this->runThroughMiddleware($firstJob);
            self::fail('The first download is still in progress.');
        } catch (ImageDownloadException $exception) {
            self::assertSame('still downloading', $exception->getMessage());
        }

        self::assertCount(3, $waitingJobs);

        foreach ($waitingJobs as [$waiting, $fake]) {
            self::assertTrue($fake->isReleased());
            self::assertSame(180, $fake->releaseDelay);
            self::assertSame(3, $waiting->remainingAttempts);
        }

        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturn(new TitleImageData(
                posterKey: null,
                posterThumbKey: null,
                bannerKey: null,
                posterSourceHash: hash('sha256', 'https://cdn.example/poster-new.jpg'),
                bannerSourceHash: null,
            ));

        $laterFake = new FakeJob;
        $laterFake->attempts = 3;
        $later = new IngestTitleImagesJob(self::CANONICAL_KEY, 'https://cdn.example/poster-new.jpg', null);
        $later->setJob($laterFake);

        $later->handle($this->handler());

        self::assertFalse($laterFake->isReleased());
        self::assertSame(3, $later->remainingAttempts);
    }

    #[Test]
    public function it_dispatches_a_follow_up_job_for_a_storage_failure(): void
    {
        Queue::fake();

        $this->givenStorageFailure();

        $job = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, null);

        $job->handle($this->handler());

        Queue::assertPushedOn(config('images.queue'), IngestTitleImagesJob::class, function (IngestTitleImagesJob $followUp): bool {
            return $followUp->canonicalKey === self::CANONICAL_KEY
                && $followUp->posterUrl === self::POSTER_URL
                && $followUp->bannerUrl === null
                && $followUp->remainingAttempts === 2
                && $followUp->delay === 45;
        });
    }

    #[Test]
    public function it_fails_storage_on_the_last_attempt(): void
    {
        Queue::fake();

        $storageException = $this->givenStorageFailure();

        $job = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, null);
        $job->remainingAttempts = 1;

        try {
            $job->handle($this->handler());
            self::fail('The last attempt must keep the storage failure.');
        } catch (ImageStorageException $exception) {
            self::assertSame($storageException, $exception);
        }

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_fails_immediately_when_the_title_is_missing(): void
    {
        Queue::fake();

        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturnNull();
        $this->imageDownloader->shouldReceive('download')->never();

        $queueJob = new FakeJob;
        $job = new IngestTitleImagesJob(self::CANONICAL_KEY, self::POSTER_URL, null);
        $job->setJob($queueJob);

        $job->handle($this->handler());

        self::assertTrue($queueJob->hasFailed());
        self::assertInstanceOf(TitleNotFoundException::class, $queueJob->failedWith);
        self::assertSame(
            'Title was not found for canonical key "naruto|anime".',
            $queueJob->failedWith->getMessage(),
        );
        self::assertFalse($queueJob->isReleased());
        Queue::assertNothingPushed();
    }


    protected function setUp(): void
    {
        parent::setUp();

        $this->imageCompressor = Mockery::mock(ImageCompressorContract::class);
        $this->titleRepository = Mockery::mock(TitleRepositoryContract::class);
        $this->imageDownloader = Mockery::mock(ImageDownloaderContract::class);
        $this->imageStorage = Mockery::mock(ImageStorageContract::class);
    }


    private function runThroughMiddleware(IngestTitleImagesJob $job): void
    {
        (new Pipeline($this->app))
            ->send($job)
            ->through($job->middleware())
            ->then(function (IngestTitleImagesJob $job): void {
                $job->handle($this->handler());
            });
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

    private function givenStorageFailure(): ImageStorageException
    {
        $storageException = ImageStorageException::storingFailed('titles/posters/key/full.webp', 45);

        $this->titleRepository->shouldReceive('findImageData')
            ->once()
            ->with(self::CANONICAL_KEY)
            ->andReturn(new TitleImageData(null, null, null, null, null));
        $this->imageDownloader->shouldReceive('download')
            ->once()
            ->with(self::POSTER_URL)
            ->andReturn(new DownloadedImage('poster-bytes', 'image/jpeg'));
        $this->imageCompressor->shouldReceive('compress')
            ->once()
            ->with('poster-bytes', ImageVariant::PosterFull)
            ->andReturn(new ProcessedImage('webp-bytes', 'image/webp', 360, 540));
        $this->imageStorage->shouldReceive('put')
            ->once()
            ->andThrow($storageException);
        $this->imageStorage->shouldReceive('deleteByPrefix')->never();

        return $storageException;
    }
}
