<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_image_ingestions', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical_key')->unique();
            $table->text('poster_url')->nullable();
            $table->text('banner_url')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->foreign('canonical_key')
                ->references('canonical_key')
                ->on('titles')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE title_image_ingestions ADD CONSTRAINT title_image_ingestions_has_url_check '
                .'CHECK (poster_url IS NOT NULL OR banner_url IS NOT NULL)',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('title_image_ingestions');
    }
};
