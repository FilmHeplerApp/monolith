<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\ImageProcessingException;
use App\Application\Media\Exceptions\UnsupportedImageException;
use Illuminate\Console\Command;

final class MeasureImageCommand extends Command
{
    protected $signature = 'filmhelper:measure-image
        {url : External image URL}
        {variant : poster_full, poster_thumb, banner, or avatar}';

    protected $description = 'Downloads one image and prints source and compressed size, geometry, and ratio';


    public function handle(
        ImageDownloaderContract $imageDownloader,
        ImageCompressorContract $imageCompressor,
    ): int {
        $variant = ImageVariant::tryFrom((string) $this->argument('variant'));

        if ($variant === null) {
            $this->error('Unknown variant. Expected one of: poster_full, poster_thumb, banner, avatar.');

            return self::FAILURE;
        }

        try {
            $downloaded = $imageDownloader->download((string) $this->argument('url'));
            $processed = $imageCompressor->compress($downloaded->imageRaw, $variant);
        } catch (ImageDownloadException|UnsupportedImageException|ImageProcessingException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $sourceSize = strlen($downloaded->imageRaw);
        $outputSize = $processed->sizeInBytes();

        $this->line('Variant: '.$variant->value);
        $this->line(sprintf(
            'Source: %d bytes, %s, %s',
            $sourceSize,
            $this->sourceGeometry($downloaded->imageRaw),
            $downloaded->mimeType,
        ));
        $this->line(sprintf(
            'Output: %d bytes, %dx%d, %s',
            $outputSize,
            $processed->width,
            $processed->height,
            $processed->mimeType,
        ));
        $this->line('Ratio: '.$this->ratio($sourceSize, $outputSize));

        return self::SUCCESS;
    }


    private function sourceGeometry(string $bytes): string
    {
        $info = getimagesizefromstring($bytes);

        if ($info === false) {
            return 'unknown';
        }

        return $info[0].'x'.$info[1];
    }

    private function ratio(int $sourceSize, int $outputSize): string
    {
        if ($outputSize === 0) {
            return 'n/a';
        }

        return sprintf('%.2fx', $sourceSize / $outputSize);
    }
}
