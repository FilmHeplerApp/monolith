<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_image_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('title_id')->constrained('titles')->cascadeOnDelete();
            $table->enum('kind', ['poster', 'banner']);
            $table->text('source_url');
            $table->char('source_hash', 64);
            $table->unsignedInteger('version');
            $table->enum('status', ['pending', 'processing', 'ready', 'failed']);
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('processing_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['title_id', 'kind']);
        });
        Schema::create('title_image_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('title_image_requests')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['request_id', 'version']);
        });
        DB::statement('CREATE INDEX title_image_outbox_pending_due_index ON title_image_outbox (available_at, id) WHERE completed_at IS NULL');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE title_image_requests ADD CONSTRAINT title_image_requests_counters_check CHECK (version > 0 AND attempts >= 0)');
            DB::statement("ALTER TABLE title_image_requests ADD CONSTRAINT title_image_requests_processing_check CHECK (
                (status = 'processing' AND processing_token IS NOT NULL AND lease_expires_at IS NOT NULL)
                OR (status <> 'processing' AND processing_token IS NULL AND lease_expires_at IS NULL)
            )");
            DB::statement('ALTER TABLE title_image_outbox ADD CONSTRAINT title_image_outbox_counters_check CHECK (version > 0 AND publish_attempts >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('title_image_outbox');
        Schema::dropIfExists('title_image_requests');
    }
};
