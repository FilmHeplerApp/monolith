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

final class AnimeStudiosArrayMigrationTest extends TestCase
{
    #[Test]
    public function it_converts_legacy_strings_preserving_ids_options_and_other_definitions(): void
    {
        $definition = $this->definition();
        $otherDefinition = $this->definition('movie');
        $id = $this->link($definition, ['value_text' => 'Студия, Inc.', 'searchable_text' => 'Студия, Inc.']);
        $otherId = $this->link($otherDefinition, ['value_text' => 'Movie studio']);
        $optionId = DB::table('attribute_options')->insertGetId(['attribute_id' => $definition, 'value_ru' => 'Студия, Inc.']);

        $this->migration()->up();

        $row = DB::table('title_attributes')->where('id', $id)->first();
        $this->assertSame(['Студия, Inc.'], json_decode($row->value_array, true, 512, JSON_THROW_ON_ERROR));
        $this->assertNull($row->value_text);
        $this->assertSame('Студия, Inc.', $row->searchable_text);
        $this->assertSame($definition, $row->attribute_id);
        $this->assertDatabaseHas('attribute_definitions', ['id' => $definition, 'value_type' => 'array']);
        $this->assertDatabaseHas('attribute_definitions', ['id' => $otherDefinition, 'value_type' => 'string']);
        $this->assertDatabaseHas('title_attributes', ['id' => $otherId, 'value_text' => 'Movie studio']);
        $this->assertDatabaseHas('attribute_options', ['id' => $optionId, 'attribute_id' => $definition, 'value_ru' => 'Студия, Inc.']);
    }

    #[Test]
    public function it_preserves_existing_arrays_and_rebuilds_searchable_text(): void
    {
        $definition = $this->definition();
        $id = $this->link($definition, ['value_array' => '["Gainax","Tatsunoko Production"]', 'searchable_text' => 'stale']);

        $this->migration()->up();

        $row = DB::table('title_attributes')->where('id', $id)->first();
        $this->assertSame(['Gainax', 'Tatsunoko Production'], json_decode($row->value_array, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame('Gainax Tatsunoko Production', $row->searchable_text);
        $this->assertNull($row->value_text);
    }

    #[Test]
    public function it_can_roll_back_single_studio_values_without_losing_data(): void
    {
        $definition = $this->definition();
        $id = $this->link($definition, ['value_text' => 'Sunrise', 'searchable_text' => 'Sunrise']);
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
        $this->link($definition, ['value_array' => '["Sunrise"]']);
        $this->link($definition, ['value_array' => '["Gainax","Tatsunoko Production"]']);
        $this->migration()->up();
        $before = DB::table('title_attributes')->orderBy('id')->get()->toArray();

        try {
            $this->migration()->down();
            $this->fail('Rollback must not discard multiple studios.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contains multiple studios', $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('title_attributes')->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('attribute_definitions', ['id' => $definition, 'value_type' => 'array']);
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function it_rolls_back_all_changes_if_a_later_link_has_an_ambiguous_value(array $value): void
    {
        $definition = $this->definition();
        $this->link($definition, ['value_text' => 'Sunrise']);
        $invalidId = $this->link($definition, $value);
        $before = DB::table('title_attributes')->orderBy('id')->get()->toArray();

        try {
            $this->migration()->up();
            $this->fail('Ambiguous studio data must stop the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("title attribute $invalidId", $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('title_attributes')->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('attribute_definitions', ['id' => $definition, 'value_type' => 'string']);
    }

    public static function invalidValues(): array
    {
        return [
            'two populated columns' => [['value_text' => 'Sunrise', 'value_array' => '["Bones"]']],
            'number including zero' => [['value_number' => 0]],
            'boolean including false' => [['value_boolean' => false]],
            'empty array' => [['value_array' => '[]']],
            'object instead of list' => [['value_array' => '{"name":"Sunrise"}']],
            'non-string element' => [['value_array' => '[1]']],
            'empty element' => [['value_array' => '[""]']],
            'blank string' => [['value_text' => ' ']],
            'no value' => [[]],
        ];
    }

    #[Test]
    public function it_leaves_an_absent_studios_definition_unchanged(): void
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
        return require database_path('migrations/2026_10_22_090000_change_anime_studios_to_array.php');
    }

    private function definition(string $contentType = 'anime'): int
    {
        return DB::table('attribute_definitions')->insertGetId([
            'content_type_code' => $contentType,
            'code' => 'studios',
            'value_type' => 'string',
        ]);
    }

    private function link(int $definitionId, array $value): int
    {
        return DB::table('title_attributes')->insertGetId(['attribute_id' => $definitionId] + $value);
    }
}
