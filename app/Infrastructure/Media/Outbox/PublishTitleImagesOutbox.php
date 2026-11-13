<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Outbox;

use App\Application\Media\Enums\TitleImageStatus;
use App\Application\Media\Jobs\ProcessTitleImageJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class PublishTitleImagesOutbox
{
    public function __construct(private Dispatcher $bus) {}

    public function publish(int $limit = 100): int
    {
        $published = 0;
        for ($index = 0; $index < $limit; $index++) {
            $event = DB::transaction(function (): ?object {
                $query = DB::table('title_image_outbox')
                    ->whereNull('completed_at')
                    ->where('available_at', '<=', now())
                    ->orderBy('available_at')
                    ->orderBy('id');
                $event = $query->lock(DB::getDriverName() === 'pgsql' ? 'for update skip locked' : true)->first();
                if ($event === null) {
                    return null;
                }
                $request = DB::table('title_image_requests')->where('id', $event->request_id)->first();
                $event->dispatch = false;
                if ($request === null || (int) $request->version !== (int) $event->version
                    || in_array($request->status, [TitleImageStatus::Ready->value, TitleImageStatus::Failed->value], true)) {
                    DB::table('title_image_outbox')
                        ->where('id', $event->id)
                        ->update(['completed_at' => now(), 'updated_at' => now()]);

                    return $event;
                }
                if (
                    $request->status === TitleImageStatus::Processing->value
                    && $request->lease_expires_at !== null
                    && Carbon::parse($request->lease_expires_at)->isFuture()
                ) {
                    DB::table('title_image_outbox')
                        ->where('id', $event->id)
                        ->update(['available_at' => $request->lease_expires_at, 'updated_at' => now()]);

                    return $event;
                }
                if ($request->retry_at !== null && Carbon::parse($request->retry_at)->isFuture()) {
                    DB::table('title_image_outbox')
                        ->where('id', $event->id)
                        ->update(['available_at' => $request->retry_at, 'updated_at' => now()]);

                    return $event;
                }
                DB::table('title_image_outbox')->where('id', $event->id)->update([
                    'available_at' => now()->addSeconds(max(240, (int) config('images.ingestion.redelivery_seconds', 360))),
                    'publish_attempts' => $event->publish_attempts + 1,
                    'updated_at' => now(),
                ]);
                $event->dispatch = true;

                return $event;
            });
            if ($event === null) {
                break;
            }
            if (!$event->dispatch) {
                continue;
            }
            try {
                $this->bus->dispatch(new ProcessTitleImageJob((int) $event->request_id, (int) $event->version));
                DB::table('title_image_outbox')->where('id', $event->id)->whereNull('completed_at')
                    ->update(['published_at' => now(), 'last_error' => null, 'updated_at' => now()]);
                $published++;
            } catch (Throwable $exception) {
                DB::table('title_image_outbox')->where('id', $event->id)->whereNull('completed_at')->update([
                    'available_at' => now()->addSeconds(max(1, (int) config('images.download.default_retry_seconds', 45))),
                    'last_error' => $exception->getMessage(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $published;
    }
}
