<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories\Catalog;

use App\Application\Catalog\Contracts\TitleRepositoryContract;
use App\Application\Catalog\DTOs\TitleImageData;
use App\Application\Catalog\Exceptions\TitleNotFoundException;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;

final class EloquentTitleRepository implements TitleRepositoryContract
{
    private const int CHUNK_SIZE = 1000;

    private const array IMAGE_COLUMNS = [
        Title::FIELD_CANONICAL_KEY,
        Title::FIELD_POSTER_KEY,
        Title::FIELD_POSTER_THUMB_KEY,
        Title::FIELD_BANNER_KEY,
        Title::FIELD_POSTER_SOURCE_HASH,
        Title::FIELD_BANNER_SOURCE_HASH,
    ];


    public function findImageData(string $canonicalKey): ?TitleImageData
    {
        $title = $this->find($canonicalKey);

        if ($title === null) {
            return null;
        }

        return self::imageData($title);
    }

    /**
     * @param  list<string>  $canonicalKeys
     * @return array<string, TitleImageData>
     */
    public function findImageDataByCanonicalKeys(array $canonicalKeys): array
    {
        $imageData = [];

        foreach (array_chunk(array_values(array_unique($canonicalKeys)), self::CHUNK_SIZE) as $chunk) {
            $titles = Title::query()
                ->whereIn(Title::FIELD_CANONICAL_KEY, $chunk)
                ->get(self::IMAGE_COLUMNS);

            foreach ($titles as $title) {
                $imageData[$title->canonical_key] = self::imageData($title);
            }
        }

        return $imageData;
    }

    public function savePoster(
        string $canonicalKey,
        string $posterKey,
        string $posterThumbKey,
        string $posterSourceHash,
    ): void {
        $this->update($canonicalKey, [
            Title::FIELD_POSTER_KEY => $posterKey,
            Title::FIELD_POSTER_THUMB_KEY => $posterThumbKey,
            Title::FIELD_POSTER_SOURCE_HASH => $posterSourceHash,
        ]);
    }

    public function saveBanner(
        string $canonicalKey,
        string $bannerKey,
        string $bannerSourceHash,
    ): void {
        $this->update($canonicalKey, [
            Title::FIELD_BANNER_KEY => $bannerKey,
            Title::FIELD_BANNER_SOURCE_HASH => $bannerSourceHash,
        ]);
    }

    public function setIsTitleIncompleteFlag(string $canonicalKey, bool $flag): void
    {
        $this->update($canonicalKey, [
            Title::FIELD_IS_INCOMPLETE => $flag,
        ]);
    }


    private static function imageData(Title $title): TitleImageData
    {
        return new TitleImageData(
            posterKey: $title->poster_key,
            posterThumbKey: $title->poster_thumb_key,
            bannerKey: $title->banner_key,
            posterSourceHash: $title->poster_source_hash,
            bannerSourceHash: $title->banner_source_hash,
        );
    }

    private function find(string $canonicalKey): ?Title
    {
        return Title::query()
            ->where(Title::FIELD_CANONICAL_KEY, $canonicalKey)
            ->first();
    }

    /**
     * @param  array<string, string>  $columns
     */
    private function update(string $canonicalKey, array $columns): void
    {
        $updated = Title::query()
            ->where(Title::FIELD_CANONICAL_KEY, $canonicalKey)
            ->update($columns);

        if ($updated === 0) {
            throw TitleNotFoundException::forCanonicalKey($canonicalKey);
        }
    }
}
