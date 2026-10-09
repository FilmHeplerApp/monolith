<?php

declare(strict_types=1);

namespace App\Application\Catalog\Contracts;

use App\Application\Catalog\DTOs\TitleImageData;
use App\Application\Catalog\Exceptions\TitleNotFoundException;

interface TitleRepositoryContract
{
    public function findImageData(string $canonicalKey): ?TitleImageData;

    /**
     * @throws TitleNotFoundException When no title has this canonical key.
     */
    public function savePoster(
        string $canonicalKey,
        string $posterKey,
        string $posterThumbKey,
        string $posterSourceHash,
    ): void;

    /**
     * @throws TitleNotFoundException When no title has this canonical key.
     */
    public function saveBanner(
        string $canonicalKey,
        string $bannerKey,
        string $bannerSourceHash,
    ): void;
}
