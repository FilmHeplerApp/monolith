<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Enums\ResizeStrategy;
use App\Application\Media\Exceptions\ImageProcessingException;

final readonly class ImageVariantSpec
{
    private const array REQUIRED_KEYS = [
        'max_width',
        'max_height',
        'strategy',
        'quality',
        'sharpen',
    ];


    public function __construct(
        public int            $maxWidth,
        public int            $maxHeight,
        public ResizeStrategy $strategy,
        public int            $quality,
        public int            $sharpen,
    ) {
    }

    /**
     * @throws ImageProcessingException When the variant is missing from config/images.php.
     */
    public static function fromVariant(ImageVariant $variant): self
    {
        $config = config('images.variants.' . $variant->value);

        if (!is_array($config) || array_diff(self::REQUIRED_KEYS, array_keys($config)) !== []) {
            throw ImageProcessingException::missingVariantConfig($variant->value);
        }

        return new self(
            maxWidth: (int)$config['max_width'],
            maxHeight: (int)$config['max_height'],
            strategy: ResizeStrategy::from((string)$config['strategy']),
            quality: (int)$config['quality'],
            sharpen: (int)$config['sharpen'],
        );
    }
}
