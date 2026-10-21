<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Shared\ValueObjects\Embedding;
use App\Infrastructure\Persistence\Eloquent\Models\User as UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserPreference as UserPreferenceModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReshapeUsersMigrationTest extends TestCase
{
    private const string CONNECTION = 'pgsql_users_migration';
    private const string SCHEMA = 'users_migration_test';

    private string $previousConnection = 'sqlite';
    private bool $schemaReady = false;


    #[Test]
    public function it_runs_the_users_migration_on_postgresql(): void
    {
        $this->migrateLaravelUsersTable();

        self::assertSame(
            'vector('.Embedding::DIMENSION.')',
            $this->formattedColumnType(UserModel::TABLE_NAME, UserModel::FIELD_EMBEDDING),
        );
        self::assertSame(
            'date',
            $this->formattedColumnType(UserModel::TABLE_NAME, UserModel::FIELD_BIRTHDAY),
        );
        self::assertTrue(Schema::hasColumn(UserModel::TABLE_NAME, UserModel::FIELD_NICKNAME));
        self::assertTrue(Schema::hasColumn(UserModel::TABLE_NAME, UserModel::FIELD_AVATAR_KEY));
        self::assertTrue(Schema::hasColumn(UserModel::TABLE_NAME, UserModel::FIELD_ROLE));
        self::assertTrue(Schema::hasColumn(UserModel::TABLE_NAME, UserModel::FIELD_SECRET_PHRASE_HASH));
        self::assertFalse(Schema::hasColumn(UserModel::TABLE_NAME, 'name'));
        self::assertTrue(Schema::hasTable(UserPreferenceModel::TABLE_NAME));

        $index = DB::selectOne(
            'select indexdef from pg_indexes where schemaname = ? and indexname = ?',
            [self::SCHEMA, UserModel::NICKNAME_LOWER_UNIQUE_INDEX],
        );

        self::assertNotNull($index);
        $definition = strtolower((string) $index->indexdef);
        self::assertStringContainsString('unique', $definition);
        self::assertMatchesRegularExpression('/lower\(\(?nickname\)?/', $definition);
    }

    #[Test]
    public function it_writes_and_reads_a_vector_of_1536_numbers(): void
    {
        $this->migrateLaravelUsersTable();

        $vector = array_map(
            static fn (int $index): float => (float) $index,
            range(0, Embedding::DIMENSION - 1),
        );

        $user = UserModel::query()->create([
            UserModel::FIELD_NICKNAME => 'anton',
            UserModel::FIELD_EMAIL => 'anton@example.com',
            UserModel::FIELD_PASSWORD => 'password-hash',
            UserModel::FIELD_BIRTHDAY => '1994-03-12',
            UserModel::FIELD_SECRET_PHRASE_HASH => 'phrase-hash',
            UserModel::FIELD_EMBEDDING => $vector,
        ]);

        $stored = UserModel::query()->find($user->id)?->embedding;

        self::assertSame($vector, $stored);
    }


    protected function setUp(): void
    {
        parent::setUp();

        $this->previousConnection = (string) config('database.default');
        config([
            'database.connections.'.self::CONNECTION => $this->connectionConfig('public'),
        ]);
        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->statement(
            'drop schema if exists '.self::SCHEMA.' cascade',
        );
        DB::connection(self::CONNECTION)->statement(
            'create schema '.self::SCHEMA,
        );
        $this->schemaReady = true;

        config([
            'database.connections.'.self::CONNECTION.'.search_path' => self::SCHEMA.', public',
            'database.default' => self::CONNECTION,
        ]);
        DB::purge(self::CONNECTION);
        DB::reconnect(self::CONNECTION);
    }

    protected function tearDown(): void
    {
        if ($this->schemaReady) {
            DB::connection(self::CONNECTION)->statement(
                'drop schema if exists '.self::SCHEMA.' cascade',
            );
        }

        config(['database.default' => $this->previousConnection]);
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }


    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(string $searchPath): array
    {
        return [
            'driver' => 'pgsql',
            'host' => $this->envValue('DB_HOST', 'postgres'),
            'port' => $this->envValue('DB_PORT', '5432'),
            'database' => $this->envValue('DB_DATABASE', 'filmhelper'),
            'username' => $this->envValue('DB_USERNAME', 'filmhelper'),
            'password' => $this->envValue('DB_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => $searchPath,
            'sslmode' => 'prefer',
        ];
    }

    private function envValue(string $key, string $fallback): string
    {
        $path = base_path('.env');

        if (!is_file($path)) {
            return $fallback;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return $fallback;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);

            if (trim($name) !== $key) {
                continue;
            }

            $value = trim($value);
            $value = trim($value, "\"'");

            return $value !== '' ? $value : $fallback;
        }

        return $fallback;
    }

    private function migrateLaravelUsersTable(): void
    {
        Schema::create(UserModel::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        $migration = require database_path(
            'migrations/2026_10_19_210600_reshape_users_and_create_user_preferences.php',
        );
        $migration->up();
    }

    private function formattedColumnType(string $table, string $column): string
    {
        $row = DB::selectOne(
            <<<'SQL'
            select format_type(a.atttypid, a.atttypmod) as type
            from pg_attribute a
            join pg_class c on c.oid = a.attrelid
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = ?
              and c.relname = ?
              and a.attname = ?
              and a.attnum > 0
              and not a.attisdropped
            SQL,
            [self::SCHEMA, $table, $column],
        );

        self::assertNotNull($row);

        return (string) $row->type;
    }
}
