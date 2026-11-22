<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

use App\Application\Media\DTOs\PendingTitleImageIngestion;
use App\Application\Media\DTOs\TitleImageIngestionBacklog;
use App\Application\Media\DTOs\TitleImageIngestionRequest;

interface TitleImageIngestionOutboxContract
{
    /**
     * @param  list<TitleImageIngestionRequest>  $requests
     */
    public function enqueue(array $requests): void;

    /**
     * @param  list<string>  $canonicalKeys
     */
    public function discard(array $canonicalKeys): void;

    /**
     * @return list<PendingTitleImageIngestion>
     */
    public function pending(int $afterId, int $limit): array;

    public function acknowledge(int $id, int $revision): bool;

    public function backlog(): TitleImageIngestionBacklog;
}
