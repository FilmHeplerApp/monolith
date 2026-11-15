<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class AnimeTargetAudienceArrayMigrationTest extends TestCase
{
    #[Test]
    public function it_converts_legacy_strings_preserving_ids_options_and_other_definitions(): void
    {
        $definition = $this->definition();
        $otherDefinition = $this->definition('movie');
        $id = $this->link($definition, ['value_text' => 'Детское, Сёнен', 'searchable_text' => 'Детское, Сёнен']);
        $otherId = $this->link($otherDefinition, ['value_text' => 'Movie audience']);
        $optionId = DB::table('attribute_options')->insertGetId(['attribute_id' => $definition, 'value_ru' => 'Детское, Сёнен']);

        $this->migration()->up();

        $row = DB::table('title_attributes')->where('id', $id)->first();
        $this->assertSame(['Детское, Сёнен'], json_decode($row->value_array, true, 512, JSON_THROW_ON_ERROR));
        $this->assertNull($row->value_text);
        $this->assertSame('Детское, Сёнен', $row->searchable_text);
        $this->assertSame($definition, $row->attribute_id);
        $this->assertDatabaseHas('attribute_definitions', ['id' => $definition, 'value_type' => 'array']);
        $this->assertDatabaseHas('attribute_definitions', ['id' => $otherDefinition, 'value_type' => 'string']);
        $this->assertDatabaseHas('title_attributes', ['id' => $otherId, 'value_text' => 'Movie audience']);
        $this->assertDatabaseHas('attribute_options', ['id' => $optionId, 'attribute_id' => $definition, 'value_ru' => 'Детское, Сёнен']);
    }

    #[Test]
    public function it_preserves_existing_arrays_and_rebuilds_searchable_text(): void
    {
        $definition = $this->definition();
        $id = $this->link($definition, ['value_array' => '["Детское","Сёнен"]', 'searchable_text' => 'stale']);

        $this->migration()->up();

        $row = DB::table('title_attributes')->where('id', $id)->first();
        $this->assertSame(['Детское', 'Сёнен'], json_decode($row->value_array, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame('Детское Сёнен', $row->searchable_text);
        $this->assertNull($row->value_text);
    }

    #[Test]
    public function it_can_roll_back_single_demographic_values_without_losing_data(): void
    {
        $definition = $this->definition();
        $id = $this->link($definition, ['value_text' => 'Сёнен', 'searchable_text' => 'Сёнен']);
        $before = DB::table('title_attributes')->where('id', $id)->first();

        $this->migration()->up();
        $this->migration()->down();

        $this->assertEquals($before, DB::table('title_attributes')->where('id', $id)->first());
        $this->assertDatabaseHas('attribute_definitions', ['id' => $definition, 'value_type' => 'string']);
    }

    #[Test]
    public function it_refuses_a_lossy_rollback_and_restores_rows_already_processed(): void
    {
        $definition = $this->definition();
        $this->link($definition, ['value_array' => '["Сёнен"]']);
        $this->link($definition, ['value_array' => '["Детское","Сёнен"]']);
        $this->migration()->up();
        $before = DB::table('title_attributes')->orderBy('id')->get()->toArray();

        try {
            $this->migration()->down();
            $this->fail('Rollback must not discard multiple target_audience.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contains multiple target_audience', $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('title_attributes')->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('attribute_definitions', ['id' => $definition, 'value_type' => 'array']);
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function it_rolls_back_all_changes_if_a_later_link_has_an_ambiguous_value(array $value): void
    {
        $definition = $this->definition();
        $this->link($definition, ['value_text' => 'Сёнен']);
        $invalidId = $this->link($definition, $value);
        $before = DB::table('title_attributes')->orderBy('id')->get()->toArray();

        try {
            $this->migration()->up();
            $this->fail('Ambiguous demographic data must stop the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("title attribute $invalidId", $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('title_attributes')->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('attribute_definitions', ['id' => $definition, 'value_type' => 'string']);
    }

    public static function invalidValues(): array
    {
        return [
            'two populated columns' => [['value_text' => 'Сёнен', 'value_array' => '["Сэйнэн"]']],
            'number including zero' => [['value_number' => 0]],
            'boolean including false' => [['value_boolean' => false]],
            'empty array' => [['value_array' => '[]']],
            'object instead of list' => [['value_array' => '{"name":"Сёнен"}']],
            'non-string element' => [['value_array' => '[1]']],
            'empty element' => [['value_array' => '[""]']],
            'blank string' => [['value_text' => ' ']],
            'no value' => [[]],
        ];
    }

    #[Test]
    public function it_leaves_an_absent_target_audience_definition_unchanged(): void
    {
        $this->migration()->up();
        $this->migration()->down();

        $this->assertDatabaseCount('attribute_definitions', 0);
        $this->assertDatabaseCount('title_attributes', 0);
    }

    #[Test]
    public function it_changes_an_unused_definition_without_creating_links(): void
    {
        $definition = $this->definition();

        $this->migration()->up();
        $this->assertDatabaseHas('attribute_definitions', ['id' => $definition, 'value_type' => 'array']);
        $this->migration()->down();

        $this->assertDatabaseHas('attribute_definitions', ['id' => $definition, 'value_type' => 'string']);
        $this->assertDatabaseCount('title_attributes', 0);
    }


    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('attribute_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('content_type_code');
            $table->string('code');
            $table->string('value_type');
            $table->unique(['content_type_code', 'code']);
        });
        Schema::create('title_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attribute_definitions');
            $table->string('value_text')->nullable();
            $table->json('value_array')->nullable();
            $table->decimal('value_number', 15, 4)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->text('searchable_text')->nullable();
        });
        Schema::create('attribute_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attribute_definitions');
            $table->string('value_ru');
        });
    }


    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_22_100000_change_anime_target_audience_to_array.php');
    }

    private function definition(string $contentType = 'anime'): int
    {
        return DB::table('attribute_definitions')->insertGetId([
            'content_type_code' => $contentType,
            'code' => 'target_audience',
            'value_type' => 'string',
        ]);
    }

    private function link(int $definitionId, array $value): int
    {
        return DB::table('title_attributes')->insertGetId(['attribute_id' => $definitionId] + $value);
    }
}
