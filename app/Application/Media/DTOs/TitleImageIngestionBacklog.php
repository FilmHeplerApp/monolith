<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class TitleImageIngestionBacklog
{
    public function __construct(
        public int                $count,
        public ?DateTimeImmutable $oldestCreatedAt,
    ) {}

    public function oldestAgeSeconds(DateTimeInterface $now): int
    {
        if ($this->oldestCreatedAt === null) {
            return 0;
        }

        return max(0, $now->getTimestamp() - $this->oldestCreatedAt->getTimestamp());
    }
}
