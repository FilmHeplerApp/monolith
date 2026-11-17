<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Catalog\DTOs\PreparedCatalogDto;
use App\Application\Import\DTOs\CatalogPackInput;
use App\Application\Import\Services\CatalogImportBatchSaver;
use App\Application\Import\Services\CatalogPacker;
use App\Infrastructure\Persistence\Eloquent\Import\EloquentAttributeOptionReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CatalogOptionBatches;
use Tests\Support\CreatesCatalogSchema;
use Tests\Support\UsesIsolatedPostgresSchema;
use Tests\TestCase;

final class CatalogOptionTranslationTest extends TestCase
{
    use CreatesCatalogSchema;
    use UsesIsolatedPostgresSchema;


    public function test_it_preserves_and_replaces_translations_across_batches(): void
    {
        $saver = app(CatalogImportBatchSaver::class);
        $saver->save([CatalogOptionBatches::title('Sunrise Studio')]);
        $id = DB::table('attribute_options')->value('id');
        $saver->save([CatalogOptionBatches::title(null)]);
        self::assertSame('Sunrise Studio', DB::table('attribute_options')->value('value_en'));
        $saver->save([CatalogOptionBatches::title('New Sunrise')]);
        self::assertSame('New Sunrise', DB::table('attribute_options')->value('value_en'));
        self::assertSame($id, DB::table('attribute_options')->value('id'));
        self::assertSame(1, DB::table('attribute_options')->count());
    }

    public function test_it_keeps_missing_translations_null(): void
    {
        $saver = app(CatalogImportBatchSaver::class);
        $saver->save([CatalogOptionBatches::title(null)]);
        $saver->save([CatalogOptionBatches::title(null)]);
        self::assertNull(DB::table('attribute_options')->value('value_en'));
        self::assertSame(1, DB::table('attribute_options')->count());
    }

    public function test_it_matches_the_packed_fallback_value(): void
    {
        $saver = app(CatalogImportBatchSaver::class);
        $saver->save([CatalogOptionBatches::title('Sunrise', russian: null)]);
        $saver->save([CatalogOptionBatches::title(null)]);
        $this->assertDatabaseHas('attribute_options', ['value_ru' => 'Sunrise', 'value_en' => 'Sunrise']);
        self::assertSame(1, DB::table('attribute_options')->count());
    }

    public function test_it_separates_content_types_and_attribute_codes(): void
    {
        $saver = app(CatalogImportBatchSaver::class);
        $saver->save([
            CatalogOptionBatches::title('Studio'),
            CatalogOptionBatches::title('Genre', code: 'genres', key: 'genre'),
            CatalogOptionBatches::title('Movie studio', type: 'movie'),
        ]);
        $saver->save([
            CatalogOptionBatches::title(null),
            CatalogOptionBatches::title(null, code: 'genres', key: 'genre'),
            CatalogOptionBatches::title(null, type: 'movie'),
        ]);
        self::assertSame(['Genre', 'Movie studio', 'Studio'], DB::table('attribute_options')->orderBy('value_en')->pluck('value_en')->all());
    }

    public function test_it_reads_only_requested_options_in_bounded_batches(): void
    {
        $titles = [];
        for ($index = 0; $index < 501; $index++) {
            $titles[] = CatalogOptionBatches::title('English '.$index, russian: 'Значение '.$index, key: (string) $index);
        }
        app(CatalogImportBatchSaver::class)->save($titles);
        $options = new CatalogPacker()->pack(new CatalogPackInput($titles, []))->attributeOptions;
        $options[] = $options[0];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $reader = new EloquentAttributeOptionReader;
        $stored = $reader->read($options);
        self::assertCount(501, $stored);
        self::assertCount(3, DB::getQueryLog());
        DB::flushQueryLog();
        self::assertSame([], $reader->read([]));
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        self::assertCount(1, $reader->read([$options[0]]));
    }

    public function test_it_does_not_read_options_when_incoming_translations_are_filled(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(CatalogImportBatchSaver::class)->save([CatalogOptionBatches::title('Sunrise Studio')]);
        $optionReads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && str_contains($query['query'], 'attribute_options')
        );
        self::assertSame([], $optionReads);
        DB::disableQueryLog();
    }

    public function test_it_rolls_back_catalog_writes_when_saving_fails(): void
    {
        app(CatalogImportBatchSaver::class)->save([CatalogOptionBatches::title('Old')]);
        $writer = app(CatalogBulkWriterContract::class);
        $failing = new class($writer) implements CatalogBulkWriterContract
        {
            public function __construct(private CatalogBulkWriterContract $writer) {}

            public function write(PreparedCatalogDto $preparedCatalogDto): void
            {
                $this->writer->write($preparedCatalogDto);
                throw new RuntimeException('Save failed');
            }
        };
        try {
            app(CatalogImportBatchSaver::class, ['writer' => $failing])->save([CatalogOptionBatches::title('New', key: 'new')]);
            self::fail('Saving must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Save failed', $exception->getMessage());
        }
        self::assertSame('Old', DB::table('attribute_options')->value('value_en'));
        self::assertSame(1, DB::table('titles')->count());
    }

    public function test_an_outer_rollback_reverts_a_successfully_saved_batch(): void
    {
        DB::beginTransaction();
        app(CatalogImportBatchSaver::class)->save([CatalogOptionBatches::title('Transient')]);
        self::assertSame(1, DB::table('attribute_options')->count());
        DB::rollBack();
        self::assertSame(0, DB::table('attribute_options')->count());
        self::assertSame(0, DB::table('titles')->count());
    }

    public function test_an_empty_batch_does_not_read_or_write(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(CatalogImportBatchSaver::class)->save([]);
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }


    protected function setUp(): void
    {
        parent::setUp();
        $this->createCatalogSchema();
        foreach ([['anime', 'studios'], ['anime', 'genres'], ['movie', 'studios']] as [$type, $code]) {
            DB::table('attribute_definitions')->insert([
                'content_type_code' => $type, 'code' => $code,
                'name_ru' => $code, 'name_en' => $code, 'value_type' => 'array',
            ]);
        }
    }
}
