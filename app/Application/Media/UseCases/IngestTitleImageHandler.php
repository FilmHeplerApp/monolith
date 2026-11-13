<?php

declare(strict_types=1);

namespace App\Application\Media\UseCases;

use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\Contracts\TitleImageRequestRepositoryContract;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\ImageProcessingException;
use App\Application\Media\Exceptions\ImageStorageException;
use App\Application\Media\Exceptions\UnsupportedImageException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class IngestTitleImageHandler
{
    public function __construct(
        private TitleImageRequestRepositoryContract $requests,
        private ImageDownloaderContract             $downloader,
        private StoreRemoteImageHandler             $store,
        private ImageStorageContract                $storage,
    ) {}

    public function handle(int $requestId, int $version): void
    {
        $work = $this->requests->claim($requestId, $version);
        if ($work === null) {
            return;
        }
        $uuid = Str::uuid()->toString();
        $variants = $work->kind->variants();
        $prefix = dirname($variants[0]->objectKey($uuid));
        $publishing = false;
        try {
            $image = $this->downloader->download($work->sourceUrl);
            $keys = [];
            foreach ($variants as $variant) {
                $keys[$variant->value] = $this->store->handle($image->imageRaw, $variant, $uuid)->key;
            }
            $publishing = true;
            $oldPrefixes = $this->requests->complete($work, $keys);
        } catch (Throwable $exception) {
            if (!$publishing) {
                $this->cleanup($prefix);
            }
            $this->requests->fail(
                $work,
                $exception->getMessage(),
                !($exception instanceof UnsupportedImageException || $exception instanceof ImageProcessingException),
                ($exception instanceof ImageDownloadException || $exception instanceof ImageStorageException)
                    ? ($exception->retryAfterSeconds ?? (int) config('images.download.default_retry_seconds', 45))
                    : (int) config('images.download.default_retry_seconds', 45),
            );

            return;
        }
        if ($oldPrefixes === null) {
            $this->cleanup($prefix);

            return;
        }
        foreach ($oldPrefixes as $oldPrefix) {
            $this->cleanup($oldPrefix);
        }
    }


    private function cleanup(string $prefix): void
    {
        if (!preg_match('~\Atitles/(?:posters|banners)/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z~i', $prefix)) {
            return;
        }
        try {
            $this->storage->deleteByPrefix($prefix);
        } catch (Throwable $exception) {
            try {
                Log::warning('Failed to remove unused title images.', [
                    'prefix' => $prefix, 'exception' => $exception::class, 'message' => $exception->getMessage(),
                ]);
            } catch (Throwable) {
            }
        }
    }
}
