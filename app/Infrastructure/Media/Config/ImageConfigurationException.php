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

    public static function invalid(string $key, mixed $value): self
    {
        return new self(sprintf('Image config "%s" is missing or has an invalid value: %s.', $key, self::describe($value)));
    }


    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $encoded === false ? get_debug_type($value) : $encoded;
    }
}
