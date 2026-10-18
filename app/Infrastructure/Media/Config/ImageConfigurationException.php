<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Config;

use RuntimeException;

final class ImageConfigurationException extends RuntimeException
{
    public static function missingVariant(string $variant): self
    {
        return new self(sprintf('Image variant "%s" is not configured in config/images.php.', $variant));
    }

    public static function invalid(string $key): self
    {
        return new self(sprintf('Image config "%s" is missing or has an invalid value.', $key));
    }
}
