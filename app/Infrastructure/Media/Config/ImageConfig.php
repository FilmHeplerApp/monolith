<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Config;

use App\Application\Media\DTOs\ImageVariantSpec;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Enums\ResizeStrategy;

final class ImageConfig
{
    private const string ROOT = 'images';

    private const array VARIANT_KEYS = [
        'max_width',
        'max_height',
        'strategy',
        'quality',
        'sharpen',
    ];


    public static function disk(): string
    {
        return self::string('disk');
    }

    public static function queue(): string
    {
        return self::string('queue');
    }

    /**
     * @throws ImageConfigurationException
     */
    public static function variant(ImageVariant $variant): ImageVariantSpec
    {
        $config = config(self::ROOT . '.variants.' . $variant->value);

        if (!is_array($config) || array_diff(self::VARIANT_KEYS, array_keys($config)) !== []) {
            throw ImageConfigurationException::missingVariant($variant->value);
        }

        try {
            $strategy = ResizeStrategy::from((string)$config['strategy']);
        } catch (\ValueError) {
            throw ImageConfigurationException::invalid(self::ROOT . '.variants.' . $variant->value . '.strategy');
        }

        return new ImageVariantSpec(
            maxWidth: (int)$config['max_width'],
            maxHeight: (int)$config['max_height'],
            strategy: $strategy,
            quality: (int)$config['quality'],
            sharpen: (int)$config['sharpen'],
        );
    }

    public static function maxBytes(): int
    {
        return self::int('input.max_bytes');
    }

    public static function maxPixels(): int
    {
        return self::int('input.max_pixels');
    }

    /**
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        return self::stringList('input.allowed_mime');
    }

    /**
     * @return list<string>
     */
    public static function allowedSchemes(): array
    {
        return self::stringList('download.allowed_schemes');
    }

    public static function connectTimeout(): int
    {
        return self::int('download.connect_timeout');
    }

    public static function timeout(): int
    {
        return self::int('download.timeout');
    }

    public static function outputFormat(): string
    {
        return self::string('output.format');
    }

    public static function cacheControl(): string
    {
        return self::string('output.cache_control');
    }


    private static function string(string $key): string
    {
        $value = config(self::ROOT . '.' . $key);

        if (!is_string($value) || $value === '') {
            throw ImageConfigurationException::invalid(self::ROOT . '.' . $key);
        }

        return $value;
    }

    private static function int(string $key): int
    {
        $value = config(self::ROOT . '.' . $key);

        if (!is_int($value)) {
            throw ImageConfigurationException::invalid(self::ROOT . '.' . $key);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function stringList(string $key): array
    {
        $value = config(self::ROOT . '.' . $key);
        $configKey = self::ROOT . '.' . $key;

        if (!is_array($value) || $value === [] || array_filter($value, is_string(...)) !== $value) {
            throw ImageConfigurationException::invalid($configKey);
        }

        return array_values($value);
    }
}
