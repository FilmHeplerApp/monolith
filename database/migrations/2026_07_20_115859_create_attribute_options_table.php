<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attribute_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attribute_definitions')->cascadeOnDelete();
            $table->string('value_ru');
            $table->string('value_en')->nullable();
            $table->timestamps();

            $table->unique(['attribute_id', 'value_ru']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_options');
    }
};
