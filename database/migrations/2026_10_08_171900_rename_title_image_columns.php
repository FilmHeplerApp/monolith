<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->renameColumn('poster_url', 'poster_key');
            $table->renameColumn('banner_url', 'banner_key');
        });

        Schema::table('titles', function (Blueprint $table) {
            $table->string('poster_thumb_key')->nullable()->after('poster_key');
            $table->char('poster_source_hash', 64)->nullable()->after('banner_key');
            $table->char('banner_source_hash', 64)->nullable()->after('poster_source_hash');
        });
    }

    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->dropColumn([
                'poster_thumb_key',
                'poster_source_hash',
                'banner_source_hash',
            ]);
        });

        Schema::table('titles', function (Blueprint $table) {
            $table->renameColumn('poster_key', 'poster_url');
            $table->renameColumn('banner_key', 'banner_url');
        });
    }
};
