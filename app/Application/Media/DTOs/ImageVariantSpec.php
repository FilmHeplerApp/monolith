<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

use App\Application\Media\Enums\ResizeStrategy;

final readonly class ImageVariantSpec
{
    public function __construct(
        public int            $maxWidth,
        public int            $maxHeight,
        public ResizeStrategy $strategy,
        public int            $quality,
        public int            $sharpen,
    ) {
    }
}
