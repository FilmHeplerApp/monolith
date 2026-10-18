<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribute_definitions', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['content_type_code', 'code']);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE attribute_definitions IN ACCESS EXCLUSIVE MODE');
            }

            $hasDuplicateCodes = DB::table('attribute_definitions')
                ->select('code')
                ->groupBy('code')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($hasDuplicateCodes) {
                throw new RuntimeException(
                    'Cannot roll back attribute definitions uniqueness: codes are shared by multiple content types. '
                    .'Migrate these definitions and their references explicitly before retrying the rollback.',
                );
            }

            Schema::table('attribute_definitions', function (Blueprint $table): void {
                $table->unique('code');
                $table->dropUnique(['content_type_code', 'code']);
            });
        });
    }
};
