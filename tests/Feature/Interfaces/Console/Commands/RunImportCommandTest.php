<?php

declare(strict_types=1);

namespace Tests\Feature\Interfaces\Console\Commands;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\ImportRunStatus;
use App\Domain\Import\ValueObjects\FilterDecision;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RunImportCommandTest extends TestCase
{
    private CommandCandidateSink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createImportTables();

        config()->set('shikimori.dump_path', base_path('tests/Fixtures/Shikimori/animes.ndjson'));
        config()->set('import.filter.allowed_types', ['anime']);
        config()->set('import.filter.min_release_year', null);
        config()->set('import.filter.min_provider_score', null);
        config()->set('import.filter.min_provider_score_count', null);
        config()->set('import.checkpoint_every', 1);

        $this->sink = new CommandCandidateSink;
        $this->app->instance(CandidateSinkContract::class, $this->sink);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('import_rejections');
        Schema::dropIfExists('import_runs');
        parent::tearDown();
    }

    #[Test]
    public function it_runs_the_dump_pipeline_end_to_end_without_network(): void
    {
        $exitCode = Artisan::call('import:run', [
            'provider' => 'shikimori',
            '--source' => 'dump',
        ]);

        self::assertSame(0, $exitCode, Artisan::output());

        $this->assertDatabaseHas('import_runs', [
            'id' => 1,
            'provider' => ProviderSource::Shikimori->value,
            'data_source' => ProviderDataSource::Dump->value,
            'status' => ImportRunStatus::Completed->value,
            'fetched' => 286,
            'rejected' => 20,
            'checkpoint' => 286,
        ]);
        self::assertCount(266, $this->sink->items);
        self::assertSame(20, DB::table('import_rejections')->count());
    }

    #[Test]
    public function it_resumes_the_latest_incomplete_run(): void
    {
        $runId = (int) DB::table('import_runs')->insertGetId([
            'provider' => ProviderSource::Shikimori->value,
            'data_source' => ProviderDataSource::Dump->value,
            'status' => ImportRunStatus::Failed->value,
            'limit' => 4,
            'checkpoint' => 2,
            'fetched' => 2,
            'accepted' => 2,
            'flagged' => 0,
            'rejected' => 0,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('import:run', [
            'provider' => 'shikimori',
            '--source' => 'dump',
            '--limit' => '4',
            '--resume' => true,
        ])->assertSuccessful()->expectsOutputToContain("Import run #{$runId} resumed and completed.");

        $this->assertDatabaseHas('import_runs', [
            'id' => $runId,
            'status' => ImportRunStatus::Completed->value,
            'fetched' => 4,
            'checkpoint' => 4,
        ]);
        self::assertCount(2, $this->sink->items);
    }

    #[Test]
    public function it_rejects_an_invalid_limit(): void
    {
        $this->artisan('import:run', ['--limit' => '0'])
            ->assertFailed()
            ->expectsOutputToContain('Limit must be a positive integer.');
    }

    #[Test]
    public function it_marks_the_run_as_failed_when_the_source_fails(): void
    {
        config()->set('shikimori.dump_path', '/tmp/filmapp-missing-shikimori-dump.ndjson');

        $this->artisan('import:run', [
            'provider' => 'shikimori',
            '--source' => 'dump',
            '--limit' => '1',
        ])->assertFailed()->expectsOutputToContain('Shikimori dump is not readable');

        $this->assertDatabaseHas('import_runs', [
            'status' => ImportRunStatus::Failed->value,
            'fetched' => 0,
            'checkpoint' => 0,
        ]);
    }

    private function createImportTables(): void
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
        });

        Schema::create('import_rejections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id');
            $table->string('external_id');
            $table->string('reason');
            $table->json('context')->nullable();
            $table->timestamps();
            $table->unique(['import_run_id', 'external_id', 'reason']);
        });
    }
}

final class CommandCandidateSink implements CandidateSinkContract
{
    /** @var list<array{candidate: TitleCandidate, decision: FilterDecision}> */
    public array $items = [];

    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        $this->items[] = compact('candidate', 'decision');
    }

    public function finish(): void {}
}
