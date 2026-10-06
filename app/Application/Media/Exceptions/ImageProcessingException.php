<?php

declare(strict_types=1);

namespace App\Application\Media\Exceptions;

use RuntimeException;

final class ImageProcessingException extends RuntimeException
{
    public static function missingVariantConfig(string $variant): self
    {
        return new self(sprintf('Image variant "%s" is not configured in config/images.php.', $variant));
    }

    public static function decodeFailed(string $reason): self
    {
        return new self(sprintf('Image could not be decoded: %s', $reason));
    }

    public static function modificationFailed(string $operation, string $reason): self
    {
        return new self(sprintf('Image could not be modified during %s: %s', $operation, $reason));
    }

    public static function encodeFailed(string $format, string $reason): self
    {
        return new self(sprintf('Image could not be encoded to %s: %s', $format, $reason));
    }
}
