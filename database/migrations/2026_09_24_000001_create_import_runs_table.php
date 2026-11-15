<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('data_source');
            $table->string('status');
            $table->unsignedInteger('limit')->nullable();
            $table->unsignedInteger('checkpoint')->default(0);
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('flagged')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'data_source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
