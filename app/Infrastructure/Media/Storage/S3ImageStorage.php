<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Storage;

use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\DTOs\StoredImage;
use App\Application\Media\Exceptions\ImageStorageException;
use App\Infrastructure\Media\Config\ImageConfig;
use Illuminate\Support\Facades\Storage;

class S3ImageStorage implements ImageStorageContract
{
    public function put(
        string $bytes,
        string $key,
        string $mimeType,
    ): StoredImage {
        $success = Storage::disk(ImageConfig::disk())
            ->put(
                $key,
                $bytes,
                [
                    'ContentType' => $mimeType,
                    'CacheControl' => ImageConfig::cacheControl(),
                    'visibility' => 'public',
                ]
            );

        if (!$success) {
            throw ImageStorageException::storingFailed($key);
        }

        return new StoredImage($key);
    }

    public function deleteByPrefix(string $prefix): void
    {
        $validString = trim(trim($prefix), '/');
        if ($validString === '') {
            throw ImageStorageException::invalidPrefix($prefix);
        }

        $success = Storage::disk(ImageConfig::disk())->deleteDirectory($prefix);

        if (!$success) {
            throw ImageStorageException::deletingFailed($prefix);
        }
    }
}
