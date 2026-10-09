<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

use App\Application\Media\DTOs\DownloadedImage;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\UnsupportedImageException;

interface ImageDownloaderContract
{
    /**
     * Downloads an image from the given URL.
     *
     * @throws ImageDownloadException When the transfer fails and a retry can succeed.
     * @throws UnsupportedImageException When the URL or the file will fail the same way again.
     */
    public function download(string $url): DownloadedImage;
}
