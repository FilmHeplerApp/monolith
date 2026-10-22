<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Compression;

use App\Application\Media\DTOs\ImageVariantSpec;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\Exceptions\ImageProcessingException;

interface SpecImageCompressor
{
    /**
     * Brings raw image bytes to the geometry and format of the given spec.
     *
     * @throws ImageProcessingException When the source cannot be decoded or the result cannot be encoded.
     */
    public function compress(string $contents, ImageVariantSpec $spec): ProcessedImage;
}
