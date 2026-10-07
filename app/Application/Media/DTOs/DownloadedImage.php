<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

final readonly class DownloadedImage
{
    public function __construct(
        public string $imageRaw,
        public string $mimeType,
    ) {
    }
}
