<?php

declare(strict_types=1);

namespace App\Application\Media\UseCases;

use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\DTOs\StoredImage;
use App\Application\Media\Enums\ImageVariant;

readonly class StoreRemoteImageHandler
{
    public function __construct(
        private ImageCompressorContract $imageCompressor,
        private ImageStorageContract    $s3Storage,
    ) {
    }

    public function handle(
        string       $imageRaw,
        ImageVariant $variant,
        string       $ingestionUuid,
    ): StoredImage {
        $processedImage = $this->imageCompressor->compress($imageRaw, $variant);

        return $this->s3Storage->put(
            bytes: $processedImage->bytes,
            key: $variant->objectKey($ingestionUuid),
            mimeType: $processedImage->mimeType);
    }
}
