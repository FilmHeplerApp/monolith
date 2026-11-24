<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\Services\CatalogImportBatchSaver;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CatalogOptionBatches;
use Tests\Support\CreatesCatalogSchema;
use Tests\Support\UsesIsolatedPostgresSchema;
use Tests\TestCase;

#[Group('pgsql')]
final class PostgresCatalogOptionTranslationTest extends TestCase
{
    use CreatesCatalogSchema;
    use UsesIsolatedPostgresSchema;


    #[DataProvider('existingOptions')]
    public function test_a_concurrent_batch_without_translation_waits_and_keeps_the_committed_translation(bool $exists, ?string $translation): void
    {
        if ($exists) {
            app(CatalogImportBatchSaver::class)->save([CatalogOptionBatches::title($translation)]);
        }
        $previousId = DB::table('attribute_options')->value('id');
        $process = null;
        $pipes = [];
        $peerName = 'catalog_option_observer_'.bin2hex(random_bytes(8));
        $peer = DB::connectUsing($peerName, DB::connection()->getConfig());
        $initialLevel = DB::transactionLevel();

        try {
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Support/SaveCatalogOptions.php')],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                base_path(),
            );
            self::assertIsResource($process);
            stream_set_timeout($pipes[1], 10);
            fwrite($pipes[0], json_encode(DB::connection()->getConfig(), JSON_THROW_ON_ERROR)."\n");
            $pid = (int) fgets($pipes[1]);
            self::assertGreaterThan(0, $pid);

            DB::beginTransaction();
            app(CatalogImportBatchSaver::class)->save([CatalogOptionBatches::title('Sunrise Studio')]);
            fwrite($pipes[0], "save\n");
            fclose($pipes[0]);
            unset($pipes[0]);
            $blocked = false;
            for ($attempt = 0; $attempt < 300; $attempt++) {
                $activity = $peer->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$pid]);
                if (($activity->wait_event_type ?? null) === 'Lock') {
                    $blocked = true;
                    break;
                }
                usleep(10_000);
            }
            self::assertTrue($blocked, 'The second batch must wait for the conflicting option row until the first import transaction commits.');
            self::assertSame($translation, $peer->table('attribute_options')->value('value_en'));
            DB::commit();

            $output = trim(stream_get_contents($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $pipes = [];
            self::assertSame(0, proc_close($process), $error);
            $process = null;
            self::assertSame([['value_ru' => 'Sunrise', 'value_en' => 'Sunrise Studio']], json_decode($output, true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(1, DB::table('attribute_options')->count());
            self::assertSame('Sunrise Studio', DB::table('attribute_options')->value('value_en'));
            if ($previousId !== null) {
                self::assertSame($previousId, DB::table('attribute_options')->value('id'));
            }
        } finally {
            while (DB::transactionLevel() > $initialLevel) {
                DB::rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
            DB::purge($peerName);
        }
    }

    public static function existingOptions(): iterable
    {
        yield 'new option' => [false, null];
        yield 'existing untranslated option' => [true, null];
        yield 'existing translation updated concurrently' => [true, 'Old translation'];
    }


    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('This regression requires concurrent PostgreSQL transactions.');
        }
        $this->createCatalogSchema();
        DB::table('attribute_definitions')->insert([
            'content_type_code' => 'anime', 'code' => 'studios',
            'name_ru' => 'Студии', 'name_en' => 'Studios', 'value_type' => 'array',
        ]);
    }
}
