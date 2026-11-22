<?php

declare(strict_types=1);

namespace App\Application\Media\DTOs;

final readonly class TitleImageIngestionPlan
{
    /**
     * @param  list<TitleImageIngestionRequest>  $enqueue
     * @param  list<string>  $discard
     */
    public function __construct(
        public array $enqueue,
        public array $discard,
        public int   $skipped,
    ) {}
}
