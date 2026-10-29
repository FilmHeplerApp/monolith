<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rejections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('reason');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->unique(['import_run_id', 'external_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rejections');
    }
};
