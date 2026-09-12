<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const int RATING_AVG_TOTAL = 3;
    private const int ZERO_VALUE = 0;
    private const int EMBEDDING_DIMENSION = 1536;
    private const array CONTENT_TYPE_CODES = [
        'anime',
        'movie',
        'series',
        'k-drama',
        'cartoon',
    ];
    private const array STATUSES = [
        'announced',
        'ongoing',
        'released',
    ];
    private const array UPDATED_BY = [
        'admin',
        'process',
    ];


    public function up(): void
    {
        Schema::create('titles', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('canonical_key')->unique();
            $table->string('title_ru')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description_ru')->nullable();
            $table->text('description_en')->nullable();
            $table->text('short_plot_ru')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->enum('type', self::CONTENT_TYPE_CODES);
            $table->enum('status', self::STATUSES);
            $table->string('poster_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->decimal('rating_avg', self::RATING_AVG_TOTAL)->default(self::ZERO_VALUE);
            $table->unsignedInteger('rating_count')->default(self::ZERO_VALUE);
            $table->vector('embedding', self::EMBEDDING_DIMENSION)->vectorIndex()->nullable();
            $table->enum('updated_by', self::UPDATED_BY)->default('process');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titles');
    }
};
