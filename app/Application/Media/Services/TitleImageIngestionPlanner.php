<?php

declare(strict_types=1);

namespace App\Application\Media\Services;

use App\Application\Catalog\DTOs\TitleImageData;
use App\Application\Media\DTOs\TitleImageIngestionPlan;
use App\Application\Media\DTOs\TitleImageIngestionRequest;
use App\Application\Media\DTOs\TitleImageSource;
use LogicException;

final class TitleImageIngestionPlanner
{
    /**
     * @param  list<TitleImageSource>  $sources
     * @param  array<string, TitleImageData>  $storedImageData
     */
    public function plan(array $sources, array $storedImageData): TitleImageIngestionPlan
    {
        $enqueue = [];
        $discard = [];
        $skipped = 0;

        foreach ($this->latestByCanonicalKey($sources) as $source) {
            $stored = $storedImageData[$source->canonicalKey]
                ?? throw new LogicException("Written title [$source->canonicalKey] has no stored row.");
            $posterUrl = $this->needsIngestion($source->posterUrl, $stored->posterSourceHash) ? $source->posterUrl : null;
            $bannerUrl = $this->needsIngestion($source->bannerUrl, $stored->bannerSourceHash) ? $source->bannerUrl : null;

            if ($source->posterUrl === null && $source->bannerUrl === null) {
                $skipped++;
            }

            if ($posterUrl === null && $bannerUrl === null) {
                $discard[] = $source->canonicalKey;

                continue;
            }

            $enqueue[] = new TitleImageIngestionRequest($source->canonicalKey, $posterUrl, $bannerUrl);
        }

        return new TitleImageIngestionPlan($enqueue, $discard, $skipped);
    }


    /**
     * @param  list<TitleImageSource>  $sources
     * @return list<TitleImageSource>
     */
    private function latestByCanonicalKey(array $sources): array
    {
        $latest = [];

        foreach ($sources as $source) {
            $latest[$source->canonicalKey] = $source;
        }

        return array_values($latest);
    }

    private function needsIngestion(?string $url, ?string $storedHash): bool
    {
        return $url !== null && TitleImageSourceHash::of($url) !== $storedHash;
    }
}
