<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

use App\Application\Media\DTOs\PendingTitleImageIngestion;

interface TitleImageIngestionQueueContract
{
    public function size(): int;

    public function push(PendingTitleImageIngestion $ingestion): void;
}
