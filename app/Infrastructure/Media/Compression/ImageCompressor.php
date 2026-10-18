<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Compression;

use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\Enums\ImageVariant;
use App\Infrastructure\Media\Config\ImageConfig;

readonly class ImageCompressor implements ImageCompressorContract
{
    public function __construct(
        private SpecImageCompressor $compressor,
    ) {
    }

    public function compress(string $contents, ImageVariant $variant): ProcessedImage
    {
        return $this->compressor->compress($contents, ImageConfig::variant($variant));
    }
}
