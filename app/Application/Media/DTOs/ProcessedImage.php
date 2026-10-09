<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

final readonly class ProcessedImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public int    $width,
        public int    $height,
    ) {
    }

    public function sizeInBytes(): int
    {
        return strlen($this->bytes);
    }
}
