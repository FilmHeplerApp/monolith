<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Media;

use App\Application\Media\Contracts\TitleImageRequestRepositoryContract;
use App\Application\Media\DTOs\TitleImageWork;
use App\Application\Media\Enums\TitleImageKind;
use App\Application\Media\Enums\TitleImageStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EloquentTitleImageRequestRepository implements TitleImageRequestRepositoryContract
{
    public function claim(int $requestId, int $version): ?TitleImageWork
    {
        return DB::transaction(function () use ($requestId, $version): ?TitleImageWork {
            $request = DB::table('title_image_requests')->where('id', $requestId)->lockForUpdate()->first();
            if ($request === null || (int) $request->version !== $version
                || in_array($request->status, [TitleImageStatus::Ready->value, TitleImageStatus::Failed->value], true)
                || ($request->retry_at !== null && Carbon::parse($request->retry_at)->isFuture())
                || ($request->status === TitleImageStatus::Processing->value && $request->lease_expires_at !== null && Carbon::parse($request->lease_expires_at)->isFuture())) {
                return null;
            }
            if ($request->attempts >= max(1, (int) config('images.ingestion.max_attempts', 3))) {
                DB::table('title_image_requests')->where('id', $requestId)->update([
                    'status' => TitleImageStatus::Failed->value,
                    'processing_token' => null,
                    'lease_expires_at' => null,
                    'last_error' => 'Image processing attempts exhausted after an interrupted worker.',
                    'updated_at' => now(),
                ]);
                $this->closeOutbox($requestId, $version);

                return null;
            }
            $token = Str::uuid()->toString();
            DB::table('title_image_requests')->where('id', $requestId)->update([
                'status' => TitleImageStatus::Processing->value,
                'attempts' => $request->attempts + 1,
                'processing_token' => $token,
                'lease_expires_at' => now()->addSeconds(max(240, (int) config('images.ingestion.lease_seconds', 300))),
                'retry_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

            return new TitleImageWork($requestId, (int) $request->title_id, $version, TitleImageKind::from($request->kind), $request->source_url, $request->source_hash, $token);
        });
    }

    public function complete(TitleImageWork $work, array $keys): ?array
    {
        return DB::transaction(function () use ($work, $keys): ?array {
            $title = DB::table('titles')->where('id', $work->titleId)->lockForUpdate()->first();
            $request = DB::table('title_image_requests')->where('id', $work->requestId)->lockForUpdate()->first();
            if ($title === null || !$this->owns($request, $work)) {
                return null;
            }
            if ($work->kind === TitleImageKind::Poster) {
                $old = array_filter([$title->poster_key, $title->poster_thumb_key]);
                $updates = ['poster_key' => $keys['poster_full'], 'poster_thumb_key' => $keys['poster_thumb'], 'poster_source_hash' => $work->sourceHash];
            } else {
                $old = array_filter([$title->banner_key]);
                $updates = ['banner_key' => $keys['banner'], 'banner_source_hash' => $work->sourceHash];
            }
            DB::table('titles')->where('id', $work->titleId)->update([...$updates, 'updated_at' => now()]);
            DB::table('title_image_requests')->where('id', $work->requestId)->update([
                'status' => TitleImageStatus::Ready->value,
                'processing_token' => null,
                'lease_expires_at' => null,
                'retry_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);
            $this->closeOutbox($work->requestId, $work->version);

            return array_values(array_unique(array_map(dirname(...), $old)));
        });
    }

    public function fail(TitleImageWork $work, string $error, bool $retryable, int $retryAfterSeconds): void
    {
        DB::transaction(function () use ($work, $error, $retryable, $retryAfterSeconds): void {
            $request = DB::table('title_image_requests')->where('id', $work->requestId)->lockForUpdate()->first();
            if (!$this->owns($request, $work)) {
                return;
            }
            $retry = $retryable && $request->attempts < max(1, (int) config('images.ingestion.max_attempts', 3));
            $retryAt = $retry ? now()->addSeconds(max(1, $retryAfterSeconds)) : null;
            DB::table('title_image_requests')->where('id', $work->requestId)->update([
                'status' => $retry ? TitleImageStatus::Pending->value : TitleImageStatus::Failed->value,
                'processing_token' => null,
                'lease_expires_at' => null,
                'retry_at' => $retryAt,
                'last_error' => $error,
                'updated_at' => now(),
            ]);
            if ($retry) {
                DB::table('title_image_outbox')->where('request_id', $work->requestId)->where('version', $work->version)->whereNull('completed_at')
                    ->update(['available_at' => $retryAt, 'updated_at' => now()]);
            } else {
                $this->closeOutbox($work->requestId, $work->version);
            }
        });
    }


    private function owns(?object $request, TitleImageWork $work): bool
    {
        return $request !== null && (int) $request->version === $work->version
            && $request->processing_token === $work->token && $request->status === TitleImageStatus::Processing->value;
    }

    private function closeOutbox(int $requestId, int $version): void
    {
        DB::table('title_image_outbox')->where('request_id', $requestId)->where('version', $version)->whereNull('completed_at')
            ->update(['completed_at' => now(), 'updated_at' => now()]);
    }
}
