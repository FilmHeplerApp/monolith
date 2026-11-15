<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Infrastructure\Import\Sinks\LogCandidateSink;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\ProviderRecords;
use Tests\Support\SingleProviderClientFactory;
use Tests\TestCase;

final class LogCandidateSinkTest extends TestCase
{
    private string $fallbackPath;
    private string $previousErrorLog;


    #[Test]
    #[DataProvider('loggingFailures')]
    public function it_keeps_committed_imports_successful_and_attempts_every_message(array $failedIds): void
    {
        $this->runImportWithLogFailures($failedIds);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function it_preserves_import_success_when_the_fallback_logger_also_throws(): void
    {
        require base_path('tests/Support/FailingImportErrorLog.php');

        $this->runImportWithLogFailures(['1', '2', '3'], true);
        self::assertCount(3, $GLOBALS['import_fallback_attempts']);
    }

    public static function loggingFailures(): array
    {
        return [
            'healthy logger' => [[]],
            'intermediate commit' => [['1']],
            'final commit' => [['3']],
            'logger unavailable throughout' => [['1', '2', '3']],
        ];
    }


    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_24_000001_create_import_runs_table.php',
            '2026_09_24_000002_create_import_rejections_table.php',
            '2026_10_22_105000_add_snapshot_id_to_import_runs_table.php',
            '2026_10_22_110000_create_import_processed_titles_table.php',
            '2026_10_29_090000_add_owner_token_to_import_runs.php',
        ] as $name) {
            (require database_path('migrations/'.$name))->up();
        }
        $this->app->bind(CandidateSinkContract::class, LogCandidateSink::class);
        $this->fallbackPath = sys_get_temp_dir().'/import-log-fallback-'.bin2hex(random_bytes(8)).'.log';
        $this->previousErrorLog = (string) ini_get('error_log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        if (file_exists($this->fallbackPath)) {
            unlink($this->fallbackPath);
        }
        parent::tearDown();
    }

    private function runImportWithLogFailures(array $failedIds, bool $fallbackFails = false): void
    {
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->fallbackPath);
        config()->set('import.checkpoint_every', 2);
        $mapper = new ShikimoriTitleMapper;
        $records = array_map(static fn (string $id) => ProviderRecords::mock($mapper->map(ProviderRecords::shikimori([
            'id' => $id,
            'russian' => 'Title '.$id,
            'status' => 'released',
            'description' => 'Description',
            'poster' => ['originalUrl' => 'https://example.test/poster.jpg'],
        ]))), ['1', '2', '3']);
        $this->app->instance(ProviderClientFactoryContract::class, new SingleProviderClientFactory(new MockProviderClient($records)));
        $attempts = [];
        Log::shouldReceive('info')->times(3)->andReturnUsing(static function (string $message, array $context) use (&$attempts, $failedIds): void {
            $attempts[] = [
                'id' => $context['external_id'],
                'transaction_level' => DB::transactionLevel(),
                'checkpoint' => DB::table('import_runs')->value('checkpoint'),
                'status' => DB::table('import_runs')->value('status'),
            ];
            if (in_array($context['external_id'], $failedIds, true)) {
                throw new RuntimeException('Logger unavailable for '.$context['external_id']);
            }
        });

        $result = app(ImportTitlesFromProvider::class)->execute(ProviderSource::Mock, ProviderDataSource::Api);

        self::assertSame(3, $result->checkpoint);
        self::assertSame(3, $result->counters->accepted);
        self::assertSame([
            ['id' => '1', 'transaction_level' => 0, 'checkpoint' => 2, 'status' => 'running'],
            ['id' => '2', 'transaction_level' => 0, 'checkpoint' => 2, 'status' => 'running'],
            ['id' => '3', 'transaction_level' => 0, 'checkpoint' => 3, 'status' => 'completed'],
        ], $attempts);
        $this->assertDatabaseHas('import_runs', ['status' => 'completed', 'checkpoint' => 3, 'error_message' => null]);
        $this->assertDatabaseCount('import_processed_titles', 3);
        if ($failedIds === [] || $fallbackFails) {
            self::assertFileDoesNotExist($this->fallbackPath);
        } else {
            $fallback = file_get_contents($this->fallbackPath);
            foreach ($failedIds as $id) {
                self::assertStringContainsString('Logger unavailable for '.$id, $fallback);
            }
        }
    }
}
