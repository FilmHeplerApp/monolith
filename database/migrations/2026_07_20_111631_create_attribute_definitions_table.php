<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name_ru');
            $table->string('name_en');
            $table->enum('type', ['string', 'array', 'number', 'boolean', 'enum']);
            $table->boolean('is_filterable')->default(true);
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index('content_type_id');
            $table->index('type');
            $table->index('order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
