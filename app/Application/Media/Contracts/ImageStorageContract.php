<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

use App\Application\Media\DTOs\StoredImage;
use App\Application\Media\Exceptions\ImageStorageException;

interface ImageStorageContract
{
    /**
     * Stores image bytes at the given object key.
     *
     * @throws ImageStorageException When the disk refuses the write.
     */
    public function put(string $bytes, string $key, string $mimeType): StoredImage;
    /**
     * Deletes every object under the given prefix.
     *
     * @throws ImageStorageException When the prefix is empty or the disk refuses the delete.
     */
    public function deleteByPrefix(string $prefix): void;
}
