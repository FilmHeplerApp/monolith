<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_processed_titles', function (Blueprint $table): void {
            $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();
            $table->string('external_id');
            $table->primary(['import_run_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_processed_titles');
    }
};
