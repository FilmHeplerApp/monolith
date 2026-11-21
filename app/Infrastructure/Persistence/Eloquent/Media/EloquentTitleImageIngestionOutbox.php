<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Media;

use App\Application\Media\Contracts\TitleImageIngestionOutboxContract;
use App\Application\Media\DTOs\PendingTitleImageIngestion;
use App\Application\Media\DTOs\TitleImageIngestionBacklog;
use App\Application\Media\DTOs\TitleImageIngestionRequest;
use App\Infrastructure\Persistence\Eloquent\Models\Media\TitleImageIngestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentTitleImageIngestionOutbox implements TitleImageIngestionOutboxContract
{
    private const int CHUNK_SIZE = 1000;


    /**
     * @param  list<TitleImageIngestionRequest>  $requests
     */
    public function enqueue(array $requests): void
    {
        if ($requests === []) {
            return;
        }

        $now = now();
        $grammar = DB::connection()->getQueryGrammar();
        $revision = $grammar->wrap(TitleImageIngestion::TABLE_NAME.'.'.TitleImageIngestion::FIELD_REVISION);

        foreach (array_chunk($requests, self::CHUNK_SIZE) as $chunk) {
            DB::table(TitleImageIngestion::TABLE_NAME)->upsert(
                array_map(
                    static fn (TitleImageIngestionRequest $request): array => [
                        TitleImageIngestion::FIELD_CANONICAL_KEY => $request->canonicalKey,
                        TitleImageIngestion::FIELD_POSTER_URL => $request->posterUrl,
                        TitleImageIngestion::FIELD_BANNER_URL => $request->bannerUrl,
                        TitleImageIngestion::FIELD_REVISION => 1,
                        TitleImageIngestion::FIELD_CREATED_AT => $now,
                        TitleImageIngestion::FIELD_UPDATED_AT => $now,
                    ],
                    $chunk,
                ),
                [TitleImageIngestion::FIELD_CANONICAL_KEY],
                [
                    TitleImageIngestion::FIELD_POSTER_URL,
                    TitleImageIngestion::FIELD_BANNER_URL,
                    TitleImageIngestion::FIELD_REVISION => DB::raw("$revision + 1"),
                    TitleImageIngestion::FIELD_UPDATED_AT,
                ],
            );
        }
    }

    /**
     * @param  list<string>  $canonicalKeys
     */
    public function discard(array $canonicalKeys): void
    {
        foreach (array_chunk($canonicalKeys, self::CHUNK_SIZE) as $chunk) {
            DB::table(TitleImageIngestion::TABLE_NAME)
                ->whereIn(TitleImageIngestion::FIELD_CANONICAL_KEY, $chunk)
                ->delete();
        }
    }

    /**
     * @return list<PendingTitleImageIngestion>
     */
    public function pending(int $afterId, int $limit): array
    {
        return DB::table(TitleImageIngestion::TABLE_NAME)
            ->where(TitleImageIngestion::FIELD_ID, '>', $afterId)
            ->orderBy(TitleImageIngestion::FIELD_ID)
            ->limit($limit)
            ->get([
                TitleImageIngestion::FIELD_ID,
                TitleImageIngestion::FIELD_REVISION,
                TitleImageIngestion::FIELD_CANONICAL_KEY,
                TitleImageIngestion::FIELD_POSTER_URL,
                TitleImageIngestion::FIELD_BANNER_URL,
            ])
            ->map(static fn (object $row): PendingTitleImageIngestion => new PendingTitleImageIngestion(
                id: (int) $row->{TitleImageIngestion::FIELD_ID},
                revision: (int) $row->{TitleImageIngestion::FIELD_REVISION},
                canonicalKey: (string) $row->{TitleImageIngestion::FIELD_CANONICAL_KEY},
                posterUrl: $row->{TitleImageIngestion::FIELD_POSTER_URL},
                bannerUrl: $row->{TitleImageIngestion::FIELD_BANNER_URL},
            ))
            ->all();
    }

    public function acknowledge(int $id, int $revision): bool
    {
        return DB::table(TitleImageIngestion::TABLE_NAME)
            ->where(TitleImageIngestion::FIELD_ID, $id)
            ->where(TitleImageIngestion::FIELD_REVISION, $revision)
            ->delete() > 0;
    }

    public function backlog(): TitleImageIngestionBacklog
    {
        $row = DB::table(TitleImageIngestion::TABLE_NAME)
            ->selectRaw('count(*) as backlog, min('.DB::connection()->getQueryGrammar()->wrap(TitleImageIngestion::FIELD_CREATED_AT).') as oldest')
            ->first();
        $oldest = $row?->oldest;

        return new TitleImageIngestionBacklog(
            count: (int) ($row?->backlog ?? 0),
            oldestCreatedAt: $oldest === null ? null : CarbonImmutable::parse($oldest)->toDateTimeImmutable(),
        );
    }
}
