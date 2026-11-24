<?php

declare(strict_types=1);

namespace App\Application\Media\UseCases;

use App\Application\Catalog\Contracts\TitleRepositoryContract;
use App\Application\Media\Contracts\TitleImageIngestionOutboxContract;
use App\Application\Media\DTOs\TitleImageIngestionPlan;
use App\Application\Media\DTOs\TitleImageSource;
use App\Application\Media\Services\TitleImageIngestionPlanner;

readonly class RecordTitleImageIngestionsHandler
{
    public function __construct(
        private TitleRepositoryContract           $titles,
        private TitleImageIngestionPlanner        $planner,
        private TitleImageIngestionOutboxContract $outbox,
    ) {}

    /**
     * @param  list<TitleImageSource>  $sources
     */
    public function handle(array $sources): TitleImageIngestionPlan
    {
        if ($sources === []) {
            return new TitleImageIngestionPlan([], [], 0);
        }

        $stored = $this->titles->findImageDataByCanonicalKeys(array_map(
            static fn (TitleImageSource $source): string => $source->canonicalKey,
            $sources,
        ));
        $plan = $this->planner->plan($sources, $stored);
        $this->outbox->enqueue($plan->enqueue);
        $this->outbox->discard($plan->discard);

        return $plan;
    }
}
