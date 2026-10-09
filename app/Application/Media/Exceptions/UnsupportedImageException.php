<?php

declare(strict_types=1);

namespace App\Application\Media\Exceptions;

use RuntimeException;

class UnsupportedImageException extends RuntimeException
{
    public static function exceedDownloadLimit(string $url): self
    {
        return new self(sprintf('The image at this address "%s" has reached its download limit.', $url));
    }

    public static function exceedPixelAmount(string $url): self
    {
        return new self(sprintf('The image at this address "%s" has reached its pixel amount limit.', $url));
    }

    public static function unsupportedMimeType(string $url, string $realMimeType): self
    {
        return new self(sprintf('The image at this address "%s" has unsupported MIME type: "%s".', $url, $realMimeType));
    }

    public static function imageNotFound(string $url): self
    {
        return new self(sprintf('The image at this address "%s" has not been found', $url));
    }

    public static function unsupportedProtocol(string $url): self
    {
        return new self(sprintf('The image at this address "%s" has not supported protocol', $url));
    }
}
