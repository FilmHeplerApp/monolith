<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Bulk\Catalog;

use App\Application\Catalog\DTOs\TitleData;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class TitleBulkWriter
{
    private const int CHUNK_SIZE = 1000;


    /** @param list<TitleData> $titleData */
    public function write(array $titleData): void
    {
        if ($titleData === []) {
            return;
        }

        $now = now();

        foreach (array_chunk($titleData, self::CHUNK_SIZE) as $dtoChunk) {
            DB::table(Title::TABLE_NAME)->upsert(
                $this->unpackTitleData($dtoChunk, $now),
                [Title::FIELD_CANONICAL_KEY],
                [
                    Title::FIELD_TITLE_RU,
                    Title::FIELD_TITLE_EN,
                    Title::FIELD_DESCRIPTION_RU,
                    Title::FIELD_DESCRIPTION_EN,
                    Title::FIELD_SHORT_PLOT_RU,
                    Title::FIELD_DURATION,
                    Title::FIELD_RELEASE_YEAR,
                    Title::FIELD_TYPE,
                    Title::FIELD_STATUS,
                    Title::FIELD_IS_INCOMPLETE,
                    Title::FIELD_UPDATED_BY,
                    Title::FIELD_UPDATED_AT,
                ],
            );
        }
    }

    /**
     * @param  list<string>  $canonicalKeys
     * @return array<string, array{id: int, contentTypeCode: string}>
     */
    public function getStoredTitlesByCanonicalKeys(array $canonicalKeys, bool $lockForUpdate = false): array
    {
        if ($canonicalKeys === []) {
            return [];
        }

        $storedTitlesByCanonicalKey = [];

        $canonicalKeys = array_values(array_unique($canonicalKeys));
        sort($canonicalKeys, SORT_STRING);

        foreach (array_chunk($canonicalKeys, self::CHUNK_SIZE) as $chunk) {
            $query = DB::table(Title::TABLE_NAME)
                ->whereIn(Title::FIELD_CANONICAL_KEY, $chunk)
                ->orderBy(Title::FIELD_CANONICAL_KEY);

            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            $rows = $query
                ->get([
                    Title::FIELD_ID,
                    Title::FIELD_CANONICAL_KEY,
                    Title::FIELD_TYPE,
                ]);

            foreach ($rows as $row) {
                $storedTitlesByCanonicalKey[(string) $row->{Title::FIELD_CANONICAL_KEY}] = [
                    'id' => (int) $row->{Title::FIELD_ID},
                    'contentTypeCode' => (string) $row->{Title::FIELD_TYPE},
                ];
            }
        }

        return $storedTitlesByCanonicalKey;
    }


    /**
     * @param  list<TitleData>  $dtoChunk
     * @return list<array<string, mixed>>
     */
    private function unpackTitleData(array $dtoChunk, DateTimeInterface $now): array
    {
        $rows = [];

        foreach ($dtoChunk as $titleData) {
            $rows[] = [
                Title::FIELD_UUID => $titleData->uuid->getValue(),
                Title::FIELD_CANONICAL_KEY => $titleData->canonicalKey->getValue(),
                Title::FIELD_TITLE_RU => $titleData->title->getRu(),
                Title::FIELD_TITLE_EN => $titleData->title->getEn(),
                Title::FIELD_DESCRIPTION_RU => $titleData->description?->getRu(),
                Title::FIELD_DESCRIPTION_EN => $titleData->description?->getEn(),
                Title::FIELD_SHORT_PLOT_RU => $titleData->shortPlotRu,
                Title::FIELD_DURATION => $titleData->duration?->getMinutes(),
                Title::FIELD_RELEASE_YEAR => $titleData->releaseYear?->getYear(),
                Title::FIELD_TYPE => $titleData->type->value,
                Title::FIELD_STATUS => $titleData->status->value,
                Title::FIELD_POSTER_KEY => $titleData->posterKey,
                Title::FIELD_POSTER_THUMB_KEY => $titleData->posterThumbKey,
                Title::FIELD_BANNER_KEY => $titleData->bannerKey,
                Title::FIELD_POSTER_SOURCE_HASH => $titleData->posterSourceHash,
                Title::FIELD_BANNER_SOURCE_HASH => $titleData->bannerSourceHash,
                Title::FIELD_IS_INCOMPLETE => $titleData->isIncomplete,
                Title::FIELD_UPDATED_BY => $titleData->updatedBy->value,
                Title::FIELD_CREATED_AT => $now,
                Title::FIELD_UPDATED_AT => $now,
            ];
        }

        return $rows;
    }
}
