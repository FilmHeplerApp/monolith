<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class AttributeDefinitionUniquenessMigrationTest extends TestCase
{
    #[Test]
    public function it_preserves_existing_ids_and_allows_shared_codes_after_up(): void
    {
        $id = DB::table('attribute_definitions')->insertGetId(['content_type_code' => 'anime', 'code' => 'genres']);

        $this->migration()->up();

        DB::table('attribute_definitions')->insert(['content_type_code' => 'movie', 'code' => 'genres']);
        $this->assertDatabaseCount('attribute_definitions', 2);
        $this->assertDatabaseHas('attribute_definitions', ['id' => $id, 'content_type_code' => 'anime', 'code' => 'genres']);
        $this->assertTrue($this->hasUniqueIndex(['content_type_code', 'code']));
        $this->assertFalse($this->hasUniqueIndex(['code']));
    }

    #[Test]
    public function it_restores_global_uniqueness_when_codes_are_not_shared(): void
    {
        DB::table('attribute_definitions')->insert([
            ['content_type_code' => 'anime', 'code' => 'genres'],
            ['content_type_code' => 'movie', 'code' => 'studios'],
        ]);
        $before = DB::table('attribute_definitions')->orderBy('id')->get()->toArray();
        $this->migration()->up();

        $this->migration()->down();

        $this->assertEquals($before, DB::table('attribute_definitions')->orderBy('id')->get()->toArray());
        $this->assertTrue($this->hasUniqueIndex(['code']));
        $this->assertFalse($this->hasUniqueIndex(['content_type_code', 'code']));
    }

    #[Test]
    public function it_refuses_rollback_without_changing_data_references_or_indexes_when_codes_are_shared(): void
    {
        $this->migration()->up();
        $animeId = DB::table('attribute_definitions')->insertGetId(['content_type_code' => 'anime', 'code' => 'genres']);
        $movieId = DB::table('attribute_definitions')->insertGetId(['content_type_code' => 'movie', 'code' => 'genres']);
        Schema::create('attribute_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attribute_definitions');
        });
        DB::table('attribute_options')->insert([['attribute_id' => $animeId], ['attribute_id' => $movieId]]);

        try {
            $this->migration()->down();
            $this->fail('Rollback must be refused before changing the schema.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
            $this->assertStringContainsString('codes are shared by multiple content types', $exception->getMessage());
        }

        $this->assertTrue($this->hasUniqueIndex(['content_type_code', 'code']));
        $this->assertFalse($this->hasUniqueIndex(['code']));
        $this->assertDatabaseCount('attribute_definitions', 2);
        $this->assertDatabaseHas('attribute_definitions', ['id' => $animeId, 'content_type_code' => 'anime', 'code' => 'genres']);
        $this->assertDatabaseHas('attribute_definitions', ['id' => $movieId, 'content_type_code' => 'movie', 'code' => 'genres']);
        $this->assertDatabaseHas('attribute_options', ['attribute_id' => $animeId]);
        $this->assertDatabaseHas('attribute_options', ['attribute_id' => $movieId]);
    }

    #[Test]
    public function it_can_roll_back_an_empty_table(): void
    {
        $this->migration()->up();

        $this->migration()->down();

        $this->assertDatabaseCount('attribute_definitions', 0);
        $this->assertTrue($this->hasUniqueIndex(['code']));
    }


    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('attribute_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('content_type_code');
            $table->string('code')->unique();
        });
    }


    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_17_191400_change_attribute_definitions_unique_to_content_type_and_code.php');
    }

    /** @param list<string> $columns */
    private function hasUniqueIndex(array $columns): bool
    {
        return array_any(
            Schema::getIndexes('attribute_definitions'), fn($index) => $index['unique'] && $index['columns'] === $columns
        );
    }
}
