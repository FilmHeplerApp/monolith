<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

final readonly class StoredImage
{
    public function __construct(
        public string $key,
    ) {
    }
}
