<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('titles', function (Blueprint $table) {
            $table->id();
            $table->id('external_id')->index();
            $table->string('title_ru');
            $table->string('title_en')->nullable();
            $table->text('description_ru')->nullable();
            $table->text('description_en')->nullable();
            $table->text('short_plot_ru')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->enum('type', [
                'anime',
                'movie',
                'cartoon',
                'series',
                'k-drama',
            ]);
            $table->enum('status', [
                'released',
                'ongoing',
                'announced',
            ]);
            $table->string('poster_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->vector('embedding')->vectorIndex()->nullable();
            $table->enum('updated_by', [
                'admin',
                'process',
            ])->default('process');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titles');
    }
};
