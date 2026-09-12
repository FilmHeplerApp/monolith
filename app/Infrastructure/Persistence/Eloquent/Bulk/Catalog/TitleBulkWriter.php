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
                [Title::FIELD_UUID],
                [
                    Title::FIELD_CANONICAL_KEY,
                    Title::FIELD_TITLE_RU,
                    Title::FIELD_TITLE_EN,
                    Title::FIELD_DESCRIPTION_RU,
                    Title::FIELD_DESCRIPTION_EN,
                    Title::FIELD_SHORT_PLOT_RU,
                    Title::FIELD_DURATION,
                    Title::FIELD_TYPE,
                    Title::FIELD_STATUS,
                    Title::FIELD_POSTER_URL,
                    Title::FIELD_BANNER_URL,
                    Title::FIELD_UPDATED_BY,
                    Title::FIELD_UPDATED_AT,
                ],
            );
        }
    }

    /**
     * @param list<TitleData> $dtoChunk
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
                Title::FIELD_TYPE => $titleData->type->value,
                Title::FIELD_STATUS => $titleData->status->value,
                Title::FIELD_POSTER_URL => $titleData->posterUrl,
                Title::FIELD_BANNER_URL => $titleData->bannerUrl,
                Title::FIELD_UPDATED_BY => $titleData->updatedBy->value,
                Title::FIELD_CREATED_AT => $now,
                Title::FIELD_UPDATED_AT => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $uuids
     * @return array<string, int>
     */
    public function getTitleIdsByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $map = [];

        foreach (array_chunk(array_values(array_unique($uuids)), self::CHUNK_SIZE) as $chunk) {
            $rows = DB::table(Title::TABLE_NAME)
                ->whereIn(Title::FIELD_UUID, $chunk)
                ->pluck(Title::FIELD_ID, Title::FIELD_UUID)
                ->toArray();

            foreach ($rows as $uuid => $id) {
                $map[(string)$uuid] = (int)$id;
            }
        }

        return $map;
    }
}
