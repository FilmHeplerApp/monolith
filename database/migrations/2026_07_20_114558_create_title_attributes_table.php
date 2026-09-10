<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const int TOTAL_DIGITS_NUMBER = 15;
    private const int DIGITS_AFTER_COMMA = 4;


    public function up(): void
    {
        Schema::create('title_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attribute_definitions')->cascadeOnDelete();
            $table->string('value_text')->nullable();
            $table->json('value_array')->nullable();
            $table->decimal('value_number', self::TOTAL_DIGITS_NUMBER, self::DIGITS_AFTER_COMMA)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->text('searchable_text')->nullable();
            $table->timestamps();

            $table->unique(['title_id', 'attribute_id']);
            $table->index('attribute_id');
            $table->fullText('searchable_text');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_attributes');
    }
};
