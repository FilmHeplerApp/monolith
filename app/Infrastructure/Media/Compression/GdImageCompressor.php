<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Compression;

use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\DTOs\ImageVariantSpec;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\Enums\ResizeStrategy;
use App\Application\Media\Exceptions\ImageProcessingException;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ImageManagerInterface;

readonly class GdImageCompressor implements ImageCompressorContract
{
    private const string OUTPUT_FORMAT = 'webp';


    public function __construct(
        private ImageManagerInterface $imageManager,
    ) {
    }

    public function compress(string $contents, ImageVariantSpec $spec): ProcessedImage
    {
        $image = $this->decode($contents);

        $sourceWidth = $image->width();
        $sourceHeight = $image->height();

        $this->resize($image, $spec);

        $wasResized = $image->width() !== $sourceWidth || $image->height() !== $sourceHeight;

        if ($wasResized && $spec->sharpen > 0) {
            $this->sharpen($image, $spec->sharpen);
        }

        $encoded = $this->encode($image, $spec->quality);

        return new ProcessedImage(
            bytes: $encoded->toString(),
            mimeType: $encoded->mediaType(),
            width: $image->width(),
            height: $image->height(),
        );
    }


    /**
     * Orientation is applied here because decoding into GD drops EXIF entirely.
     *
     * @throws ImageProcessingException
     */
    private function decode(string $contents): ImageInterface
    {
        try {
            return $this->imageManager->decodeBinary($contents)->orient();
        } catch (ImageException $exception) {
            throw ImageProcessingException::decodeFailed($exception->getMessage());
        }
    }

    /**
     * @throws ImageProcessingException
     */
    private function resize(ImageInterface $image, ImageVariantSpec $spec): void
    {
        try {
            match ($spec->strategy) {
                ResizeStrategy::Fit => $image->scaleDown($spec->maxWidth, $spec->maxHeight),
                ResizeStrategy::Cover => $image->coverDown($spec->maxWidth, $spec->maxHeight),
            };
        } catch (ImageException $exception) {
            throw ImageProcessingException::modificationFailed('resize', $exception->getMessage());
        }
    }

    /**
     * @throws ImageProcessingException
     */
    private function sharpen(ImageInterface $image, int $level): void
    {
        try {
            $image->sharpen($level);
        } catch (ImageException $exception) {
            throw ImageProcessingException::modificationFailed('sharpen', $exception->getMessage());
        }
    }

    /**
     * @throws ImageProcessingException
     */
    private function encode(ImageInterface $image, int $quality): EncodedImageInterface
    {
        try {
            return $image->encode(new WebpEncoder(quality: $quality, strip: true));
        } catch (ImageException $exception) {
            throw ImageProcessingException::encodeFailed(self::OUTPUT_FORMAT, $exception->getMessage());
        }
    }
}
