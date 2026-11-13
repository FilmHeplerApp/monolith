<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

use App\Application\Media\DTOs\TitleImageWork;

interface TitleImageRequestRepositoryContract
{
    public function claim(int $requestId, int $version): ?TitleImageWork;

    public function complete(TitleImageWork $work, array $keys): ?array;

    public function fail(TitleImageWork $work, string $error, bool $retryable, int $retryAfterSeconds): void;
}
