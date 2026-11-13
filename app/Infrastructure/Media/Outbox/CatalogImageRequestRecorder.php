<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Outbox;

use App\Application\Import\DTOs\CatalogTitlePackData;
use App\Application\Media\Enums\TitleImageKind;
use App\Application\Media\Enums\TitleImageStatus;
use Illuminate\Support\Facades\DB;

final readonly class CatalogImageRequestRecorder
{
    public function record(array $titles): void
    {
        foreach (array_chunk($titles, 500) as $chunk) {
            $chunk = array_filter($chunk, static fn (CatalogTitlePackData $title): bool =>
                trim($title->posterUrl ?? '') !== '' || trim($title->bannerUrl ?? '') !== '');
            if ($chunk === []) {
                continue;
            }
            $storedTitles = DB::table('titles')
                ->whereIn('canonical_key', array_map(
                    static fn (CatalogTitlePackData $title): string => $title->canonicalKey->getValue(), $chunk)
                )
                ->orderBy('id')->lockForUpdate()->get()->keyBy('canonical_key');
            $existing = DB::table('title_image_requests')
                ->whereIn('title_id', $storedTitles->pluck('id')->all())
                ->orderBy('title_id')
                ->orderBy('kind')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn ($row): string => $row->title_id.':'.$row->kind);
            $rows = [];
            $pending = [];
            foreach ($chunk as $title) {
                $stored = $storedTitles[$title->canonicalKey->getValue()];
                foreach (TitleImageKind::cases() as $kind) {
                    $url = $kind === TitleImageKind::Poster ? $title->posterUrl : $title->bannerUrl;
                    if ($url === null || trim($url) === '') {
                        continue;
                    }
                    $hash = hash('sha256', $url);
                    $identity = $stored->id.':'.$kind->value;
                    $previous = $existing[$identity] ?? null;
                    $isReady = $kind === TitleImageKind::Poster
                        ? $stored->poster_source_hash === $hash && $stored->poster_key !== null && $stored->poster_thumb_key !== null
                        : $stored->banner_source_hash === $hash && $stored->banner_key !== null;
                    if ($previous !== null && $previous->source_hash === $hash
                        && $previous->status !== TitleImageStatus::Failed->value
                        && ($previous->status !== TitleImageStatus::Ready->value || $isReady)) {
                        continue;
                    }
                    $version = ($previous->version ?? 0) + 1;
                    $rows[] = [
                        'title_id' => $stored->id,
                        'kind' => $kind->value,
                        'source_url' => $url,
                        'source_hash' => $hash,
                        'version' => $version,
                        'status' => $isReady ? TitleImageStatus::Ready->value : TitleImageStatus::Pending->value,
                        'attempts' => 0,
                        'processing_token' => null,
                        'lease_expires_at' => null,
                        'retry_at' => null,
                        'last_error' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $pending[$identity] = $isReady ? null : $version;
                }
            }
            if ($rows === []) {
                continue;
            }
            DB::table('title_image_requests')->upsert($rows, ['title_id', 'kind'], [
                'source_url', 'source_hash', 'version', 'status', 'attempts', 'processing_token',
                'lease_expires_at', 'retry_at', 'last_error', 'updated_at',
            ]);
            $requests = DB::table('title_image_requests')
                ->whereIn('title_id', $storedTitles->pluck('id')->all())
                ->get();
            $changedIds = [];
            $events = [];
            foreach ($requests as $request) {
                $identity = $request->title_id.':'.$request->kind;
                if (!array_key_exists($identity, $pending)) {
                    continue;
                }
                $changedIds[] = $request->id;
                if ($pending[$identity] !== null) {
                    $events[] = [
                        'request_id' => $request->id,
                        'version' => $request->version,
                        'available_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            DB::table('title_image_outbox')
                ->whereIn('request_id', $changedIds)
                ->whereNull('completed_at')
                ->update(['completed_at' => now(), 'updated_at' => now()]);
            if ($events !== []) {
                DB::table('title_image_outbox')->insert($events);
            }
        }
    }
}
