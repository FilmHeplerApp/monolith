<?php

declare(strict_types=1);

namespace App\Application\Media\UseCases;

use App\Application\Catalog\Contracts\TitleRepositoryContract;
use App\Application\Catalog\DTOs\TitleImageData;
use App\Application\Catalog\Exceptions\TitleNotFoundException;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\ImageProcessingException;
use App\Application\Media\Exceptions\ImageStorageException;
use App\Application\Media\Exceptions\UnsupportedImageException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

readonly class IngestTitleImagesHandler
{
    public function __construct(
        private StoreRemoteImageHandler $storeRemoteImageHandler,
        private TitleRepositoryContract $titleRepository,
        private ImageDownloaderContract $imageDownloader,
        private ImageStorageContract    $imageStorage,
    ) {
    }

    /**
     * @throws TitleNotFoundException
     * @throws ImageDownloadException
     * @throws ImageStorageException
     */
    public function handle(
        string  $canonicalKey,
        string  $posterUrl,
        ?string $bannerUrl,
    ): void {
        $uuid = Str::uuid()->toString();
        $titleImageData = $this->titleRepository->findImageData($canonicalKey);

        if ($titleImageData === null) {
            throw TitleNotFoundException::forCanonicalKey($canonicalKey);
        }

        $posterException = $this->ingestPoster($canonicalKey, $posterUrl, $uuid, $titleImageData);
        $bannerException = $this->ingestBanner($canonicalKey, $bannerUrl, $uuid, $titleImageData);

        if ($posterException !== null) {
            if ($bannerException !== null && $bannerUrl !== null) {
                $this->logFailure($bannerException, $canonicalKey, 'banner', $bannerUrl);
            }

            throw $posterException;
        }

        if ($bannerException !== null) {
            throw $bannerException;
        }
    }


    private function ingestPoster(
        string         $canonicalKey,
        string         $posterUrl,
        string         $uuid,
        TitleImageData $titleImageData,
    ): ImageDownloadException|ImageStorageException|null {
        $posterHash = hash('sha256', $posterUrl);

        if ($titleImageData->posterSourceHash === $posterHash) {
            return null;
        }

        $posterFull = null;

        try {
            $image = $this->imageDownloader->download($posterUrl);
            $posterFull = $this->storeRemoteImageHandler->handle(
                imageRaw: $image->imageRaw,
                variant: ImageVariant::PosterFull,
                ingestionUuid: $uuid,
            );
            $posterThumb = $this->storeRemoteImageHandler->handle(
                imageRaw: $image->imageRaw,
                variant: ImageVariant::PosterThumb,
                ingestionUuid: $uuid,
            );
            $this->titleRepository->savePoster(
                canonicalKey: $canonicalKey,
                posterKey: $posterFull->key,
                posterThumbKey: $posterThumb->key,
                posterSourceHash: $posterHash,
            );
            $this->deleteObjectPrefixSafely(
                objectKey: $titleImageData->posterKey,
                canonicalKey: $canonicalKey,
                variant: 'poster',
                externalUrl: $posterUrl,
            );
        } catch (ImageDownloadException $exception) {
            return $exception;
        } catch (UnsupportedImageException $exception) {
            $this->logFailure($exception, $canonicalKey, 'poster', $posterUrl);
        } catch (ImageStorageException $exception) {
            $this->deleteObjectPrefixSafely(
                objectKey: $posterFull?->key,
                canonicalKey: $canonicalKey,
                variant: 'poster',
                externalUrl: $posterUrl,
            );

            return $exception;
        } catch (ImageProcessingException $exception) {
            $this->logFailure($exception, $canonicalKey, 'poster', $posterUrl);
            $this->deleteObjectPrefixSafely(
                objectKey: $posterFull?->key,
                canonicalKey: $canonicalKey,
                variant: 'poster',
                externalUrl: $posterUrl,
            );
        }

        return null;
    }

    private function ingestBanner(
        string         $canonicalKey,
        ?string        $bannerUrl,
        string         $uuid,
        TitleImageData $titleImageData,
    ): ImageDownloadException|ImageStorageException|null {
        if ($bannerUrl === null) {
            return null;
        }

        $bannerHash = hash('sha256', $bannerUrl);

        if ($titleImageData->bannerSourceHash === $bannerHash) {
            return null;
        }

        try {
            $image = $this->imageDownloader->download($bannerUrl);
            $banner = $this->storeRemoteImageHandler->handle(
                imageRaw: $image->imageRaw,
                variant: ImageVariant::Banner,
                ingestionUuid: $uuid,
            );
            $this->titleRepository->saveBanner(
                canonicalKey: $canonicalKey,
                bannerKey: $banner->key,
                bannerSourceHash: $bannerHash,
            );
            $this->deleteObjectPrefixSafely(
                objectKey: $titleImageData->bannerKey,
                canonicalKey: $canonicalKey,
                variant: 'banner',
                externalUrl: $bannerUrl,
            );
        } catch (ImageDownloadException|ImageStorageException $exception) {
            return $exception;
        } catch (UnsupportedImageException|ImageProcessingException $exception) {
            $this->logFailure($exception, $canonicalKey, 'banner', $bannerUrl);
        }

        return null;
    }

    private function deleteObjectPrefixSafely(
        ?string $objectKey,
        string  $canonicalKey,
        string  $variant,
        string  $externalUrl,
    ): void {
        if ($objectKey === null) {
            return;
        }

        try {
            $this->imageStorage->deleteByPrefix(dirname($objectKey));
        } catch (ImageStorageException $exception) {
            $this->logFailure($exception, $canonicalKey, $variant, $externalUrl);
        }
    }

    private function logFailure(
        Throwable $exception,
        string    $canonicalKey,
        string    $variant,
        string    $externalUrl,
    ): void {
        Log::warning(
            $exception->getMessage(),
            [
                'title_canonical_key' => $canonicalKey,
                'variant' => $variant,
                'external_url' => $externalUrl,
            ],
        );
    }
}
