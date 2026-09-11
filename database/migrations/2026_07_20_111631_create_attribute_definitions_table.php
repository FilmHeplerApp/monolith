<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const int ZERO_VALUE = 0;
    private const array CONTENT_TYPE_CODES = [
        'anime',
        'movie',
        'series',
        'k-drama',
        'cartoon',
    ];
    private const array TYPES = [
        'string',
        'array',
        'number',
        'boolean',
        'enum',
    ];

    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->enum('content_type_code', self::CONTENT_TYPE_CODES);
            $table->string('code')->unique();
            $table->string('name_ru');
            $table->string('name_en');
            $table->enum('value_type', self::TYPES);
            $table->boolean('is_filterable')->default(true);
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('order')->default(self::ZERO_VALUE);
            $table->timestamps();

            $table->index('content_type_code');
            $table->index('value_type');
            $table->index('order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
