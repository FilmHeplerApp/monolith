<?php

declare(strict_types=1);

namespace Tests\Feature\Interfaces\Console\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use TypeError;

final class BuildShikimoriFixtureCommandTest extends TestCase
{
    #[Test]
    public function it_reports_invalid_json_and_logs_the_exception(): void
    {
        $disk = Storage::fake('local');
        $disk->put('import/shikimori_anime.ndjson', '{invalid json}');
        $disk->put('import/shikimori_genres.ndjson', '');

        File::shouldReceive('ensureDirectoryExists')->once()
            ->with(base_path('Static/Fixtures/Shikimori'));
        Log::shouldReceive('error')->once()
            ->with('Failed to build Shikimori fixture.', \Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof JsonException,
            ));

        $this->artisan('shikimori:build-fixture')
            ->expectsOutput('Failed to build Shikimori fixture: Syntax error')
            ->assertFailed();
    }

    #[Test]
    public function it_catches_errors_during_storage_initialization(): void
    {
        $exception = new TypeError('Invalid storage configuration.');
        Storage::shouldReceive('disk')->once()->with('local')->andThrow($exception);
        Log::shouldReceive('error')->once()
            ->with('Failed to build Shikimori fixture.', ['exception' => $exception]);

        $this->artisan('shikimori:build-fixture')
            ->expectsOutput('Failed to build Shikimori fixture: Invalid storage configuration.')
            ->assertFailed();
    }
}
