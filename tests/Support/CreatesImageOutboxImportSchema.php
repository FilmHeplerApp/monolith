<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesImageOutboxImportSchema
{
    use CreatesCatalogSchema;


    private function createImageOutboxImportSchema(): void
    {
        foreach ([
            '2026_09_24_000001_create_import_runs_table.php',
            '2026_09_24_000002_create_import_rejections_table.php',
            '2026_10_22_105000_add_snapshot_id_to_import_runs_table.php',
            '2026_10_22_110000_create_import_processed_titles_table.php',
            '2026_10_29_090000_add_owner_token_to_import_runs.php',
        ] as $name) {
            (require database_path('migrations/'.$name))->up();
        }

        $this->createCatalogSchema();
        (require database_path('migrations/2026_11_17_000001_create_title_image_ingestions_table.php'))->up();
    }

    private function insertTitle(string $canonicalKey): void
    {
        DB::table(Title::TABLE_NAME)->insert([
            Title::FIELD_UUID => (string) Str::uuid(),
            Title::FIELD_CANONICAL_KEY => $canonicalKey,
            Title::FIELD_TITLE_EN => $canonicalKey,
            Title::FIELD_TYPE => TitleContentType::ANIME->value,
            Title::FIELD_STATUS => TitleStatus::RELEASED->value,
            Title::FIELD_CREATED_AT => now(),
            Title::FIELD_UPDATED_AT => now(),
        ]);
    }
}
