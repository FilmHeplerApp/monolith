<?php

declare(strict_types=1);

namespace Tests\Feature\Interfaces\Console\Commands;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\ImportRunStatus;
use App\Domain\Import\Services\TitleCandidateEvaluator;
use App\Infrastructure\Import\Exceptions\ImportConfigException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use Random\RandomException;
use RuntimeException;
use Tests\Support\CommittedShikimoriDump;
use Tests\TestCase;

final class RunImportCommandTest extends TestCase
{
    private CommandCandidateSink $sink;
    private string $dumpDirectory;


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
            'snapshot_id' => (CommittedShikimoriDump::reader($this->dumpDirectory.'/shikimori_anime.ndjson'))->snapshotId(),
            'fetched' => 2,
            'accepted' => 2,
            'flagged' => 0,
            'rejected' => 0,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('import_processed_titles')->insert([
            ['import_run_id' => $runId, 'external_id' => '1'],
            ['import_run_id' => $runId, 'external_id' => '6'],
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
    public function it_fails_when_there_is_nothing_to_resume(): void
    {
        $this->artisan('import:run', [
            'provider' => 'shikimori',
            '--source' => 'dump',
            '--resume' => true,
        ])->assertFailed()->expectsOutputToContain('no import run to resume');

        $this->assertDatabaseCount('import_runs', 0);
    }

    #[Test]
    public function it_reports_an_already_completed_run(): void
    {
        $this->artisan('import:run', ['provider' => 'shikimori', '--source' => 'dump'])->assertSuccessful();
        $runId = (int) DB::table('import_runs')->value('id');

        $this->artisan('import:run', ['provider' => 'shikimori', '--source' => 'dump', '--resume' => true])
            ->assertSuccessful()
            ->expectsOutputToContain("Import run #{$runId} was already completed, nothing to do.");
    }

    #[Test]
    public function it_rejects_an_invalid_limit(): void
    {
        $this->artisan('import:run', ['provider' => 'shikimori', '--limit' => '0'])
            ->assertFailed()
            ->expectsOutputToContain('Limit must be a positive integer.');
    }

    #[Test]
    public function it_marks_the_run_as_failed_when_the_source_fails(): void
    {
        unlink($this->dumpDirectory.'/shikimori_anime.ndjson');

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

    #[Test]
    public function it_reports_that_the_provider_is_disabled(): void
    {
        config()->set('import.providers.enabled', [ProviderSource::Mock->value]);
        $this->app->forgetInstance(ProviderClientFactoryContract::class);

        $this->artisan('import:run', [
            'provider' => 'shikimori',
            '--source' => 'dump',
            '--limit' => '1',
        ])->assertFailed()->expectsOutputToContain('Provider [shikimori] is disabled. Enabled: mock.');

        self::assertSame([], $this->sink->items);
        $this->assertDatabaseCount('import_runs', 0);
    }

    #[Test]
    public function it_leaves_the_runs_of_a_disabled_provider_untouched(): void
    {
        $runId = (int) DB::table('import_runs')->insertGetId([
            'provider' => ProviderSource::Shikimori->value,
            'data_source' => ProviderDataSource::Dump->value,
            'status' => ImportRunStatus::Failed->value,
            'owner_token' => 'previous-owner',
            'checkpoint' => 0,
            'fetched' => 0,
            'accepted' => 0,
            'flagged' => 0,
            'rejected' => 0,
            'error_message' => 'Network down',
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config()->set('import.providers.enabled', [ProviderSource::Mock->value]);
        $this->app->forgetInstance(ProviderClientFactoryContract::class);

        foreach ([[], ['--resume' => true]] as $options) {
            $this->artisan('import:run', ['provider' => 'shikimori', '--source' => 'dump', ...$options])
                ->assertFailed()
                ->expectsOutputToContain('Provider [shikimori] is disabled.');
        }

        $this->assertDatabaseCount('import_runs', 1);
        $this->assertDatabaseHas('import_runs', [
            'id' => $runId,
            'status' => ImportRunStatus::Failed->value,
            'owner_token' => 'previous-owner',
            'error_message' => 'Network down',
        ]);
    }

    #[Test]
    public function it_lists_the_enabled_providers_for_an_unknown_one(): void
    {
        $this->artisan('import:run', ['provider' => 'shikimor'])
            ->assertFailed()
            ->expectsOutputToContain('Unknown provider. Enabled: mock, shikimori.');

        $this->assertDatabaseCount('import_runs', 0);
    }

    #[Test]
    public function it_refuses_a_broken_filter_config_before_creating_a_run(): void
    {
        config()->set('import.filter.allowed_types', ['animee']);
        $this->app->forgetInstance(TitleCandidateEvaluator::class);

        try {
            $this->artisan('import:run', ['provider' => 'shikimori', '--source' => 'dump'])->run();
            self::fail('A broken filter config must stop the command.');
        } catch (ImportConfigException $exception) {
            self::assertStringContainsString('Unknown content type [animee]', $exception->getMessage());
        }

        self::assertSame([], $this->sink->items);
        $this->assertDatabaseCount('import_runs', 0);
    }

    #[Test]
    public function it_requires_an_explicit_provider(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "provider")');

        $this->artisan('import:run', ['--source' => 'dump'])->run();
    }


    /**
     * @throws RandomException
     * @throws JsonException
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->createImportTables();

        $this->dumpDirectory = sys_get_temp_dir().'/shikimori-command-'.bin2hex(random_bytes(8));
        $path = CommittedShikimoriDump::create($this->dumpDirectory, file_get_contents(base_path('static/Fixtures/Shikimori/animes.ndjson')));
        CommittedShikimoriDump::configure($path);
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
        File::deleteDirectory($this->dumpDirectory);
        Schema::dropIfExists('import_processed_titles');
        Schema::dropIfExists('import_rejections');
        Schema::dropIfExists('import_runs');
        parent::tearDown();
    }


    private function createImportTables(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('data_source');
            $table->string('status');
            $table->char('snapshot_id', 64)->nullable();
            $table->char('owner_token', 32)->nullable();
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

        Schema::create('import_processed_titles', function (Blueprint $table): void {
            $table->foreignId('import_run_id');
            $table->string('external_id');
            $table->primary(['import_run_id', 'external_id']);
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
    public array $items = [];


    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        $this->items[] = compact('candidate', 'decision');
    }

    public function flush(): void {}

    public function finish(): void {}

    public function abort(): void {}
}
