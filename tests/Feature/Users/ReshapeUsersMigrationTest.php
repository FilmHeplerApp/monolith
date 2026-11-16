<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Shared\ValueObjects\Embedding;
use App\Domain\Users\Entities\User;
use App\Domain\Users\Exceptions\UserAlreadyExistsException;
use App\Domain\Users\Exceptions\UserConcurrentModificationException;
use App\Domain\Users\Services\BirthdayPolicy;
use App\Domain\Users\ValueObjects\User\Email;
use App\Domain\Users\ValueObjects\User\Nickname;
use App\Domain\Users\ValueObjects\User\PasswordHash;
use App\Domain\Users\ValueObjects\User\SecretPhraseHash;
use App\Infrastructure\Persistence\Eloquent\Models\User as UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserPreference as UserPreferenceModel;
use App\Infrastructure\Persistence\Eloquent\Repositories\Users\EloquentUserRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ReshapeUsersMigrationTest extends TestCase
{
    private const string BASE_CONNECTION = 'pgsql_testing';
    private const string CONNECTION = 'pgsql_users_migration';
    private const string PASSWORD_HASH = '$2y$04$Z2UUGoTmGSItu9967u1JNOEGIZMm4VXC1S1qTBrZIkJ/cv5vHNMuq';

    private string $previousConnection = 'sqlite';
    private bool $schemaReady = false;
    private string $schema;


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
        self::assertSame(
            'character varying('.Nickname::MAX_LENGTH.')',
            $this->formattedColumnType(UserModel::TABLE_NAME, UserModel::FIELD_NICKNAME),
        );
        self::assertSame('integer', $this->formattedColumnType(UserModel::TABLE_NAME, UserModel::FIELD_VERSION));
        self::assertTrue(Schema::hasColumn(UserModel::TABLE_NAME, UserModel::FIELD_AVATAR_KEY));
        self::assertTrue(Schema::hasColumn(UserModel::TABLE_NAME, UserModel::FIELD_ROLE));
        self::assertTrue(Schema::hasColumn(UserModel::TABLE_NAME, UserModel::FIELD_SECRET_PHRASE_HASH));
        self::assertFalse(Schema::hasColumn(UserModel::TABLE_NAME, 'name'));
        self::assertTrue(Schema::hasTable(UserPreferenceModel::TABLE_NAME));

        $index = DB::selectOne(
            'select indexdef from pg_indexes where schemaname = ? and indexname = ?',
            [$this->schema, UserModel::NICKNAME_UNIQUE_INDEX],
        );

        self::assertNotNull($index);
        $definition = strtolower((string) $index->indexdef);
        self::assertStringContainsString('unique', $definition);
        self::assertStringContainsString('(nickname)', $definition);
        self::assertStringNotContainsString('lower', $definition);
    }

    #[Test]
    public function it_writes_and_reads_a_vector_of_1536_numbers(): void
    {
        $this->migrateLaravelUsersTable();

        $vector = array_map(
            static fn (int $index): float => (float) $index,
            range(0, Embedding::DIMENSION - 1),
        );

        $user = UserModel::query()->forceCreate([
            UserModel::FIELD_NICKNAME => 'anton',
            UserModel::FIELD_EMAIL => 'anton@example.com',
            UserModel::FIELD_PASSWORD => 'password-hash',
            UserModel::FIELD_BIRTHDAY => '1994-03-12',
            UserModel::FIELD_SECRET_PHRASE_HASH => 'phrase-hash',
            UserModel::FIELD_EMBEDDING => Embedding::createFromArray($vector),
        ]);

        $stored = UserModel::query()->find($user->id)?->embedding;

        self::assertSame($vector, $stored?->getVector());
        self::assertSame(1, UserModel::query()->find($user->id)?->version);
    }

    #[Test]
    public function it_translates_unique_violations_and_checks_versions_on_postgresql(): void
    {
        $this->migrateLaravelUsersTable();
        $repository = new EloquentUserRepository;
        $saved = $repository->save($this->registerUser('Anton', 'anton@example.com'));

        foreach ([['Anton', 'other@example.com', 'Nickname is already taken.'], ['other', 'ANTON@example.com', 'Email is already taken.']] as [$nickname, $email, $message]) {
            try {
                $repository->save($this->registerUser($nickname, $email));
                self::fail('A duplicate must be rejected.');
            } catch (UserAlreadyExistsException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        $lowerCase = $repository->save($this->registerUser('anton', 'lower@example.com'));
        self::assertNotSame($saved->id, $lowerCase->id);
        self::assertSame($lowerCase->id, $repository->findByNickname(Nickname::createFromString('anton'))?->id);
        self::assertSame($saved->id, $repository->findByNickname(Nickname::createFromString('Anton'))?->id);

        $embedding = Embedding::createFromArray(array_fill(0, Embedding::DIMENSION, 0.5));
        $updated = $repository->save($saved->updateEmbedding($embedding)->changeNickname(Nickname::createFromString('mira')));

        self::assertSame(2, $updated->version);
        self::assertSame($embedding?->getVector(), $updated->embedding?->getVector());

        $this->expectException(UserConcurrentModificationException::class);

        $repository->save($saved->changeAvatar('users/avatars/stale.webp'));
    }

    #[Test]
    public function it_reports_legacy_nicknames_longer_than_the_limit_before_changing_the_schema(): void
    {
        $base = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $base->up();
        DB::table('users')->insert([
            ['name' => str_repeat('ж', Nickname::MAX_LENGTH), 'email' => 'one@example.com', 'password' => 'hash'],
            ['name' => str_repeat('ж', Nickname::MAX_LENGTH + 1), 'email' => 'two@example.com', 'password' => 'hash'],
        ]);

        $migration = require database_path('migrations/2026_10_19_210600_reshape_users_and_create_user_preferences.php');
        try {
            $migration->up();
            self::fail('Long nicknames must be shortened explicitly.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('shorten existing user names', $exception->getMessage());
        }

        self::assertTrue(Schema::hasColumn('users', 'name'));
        self::assertSame(2, DB::table('users')->count());
    }


    #[Test]
    public function it_preserves_existing_users_with_unknown_profile_fields(): void
    {
        $base = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $base->up();
        $id = DB::table('users')->insertGetId([
            'name' => 'Legacy', 'email' => 'Legacy@Example.com', 'password' => self::PASSWORD_HASH,
        ]);

        $migration = require database_path('migrations/2026_10_19_210600_reshape_users_and_create_user_preferences.php');
        $migration->up();

        $repository = new EloquentUserRepository;
        $user = $repository->findByEmail(Email::createFromString('LEGACY@example.com'));
        self::assertSame($id, $user?->id);
        self::assertSame(self::PASSWORD_HASH, $user?->password->getValue());
        self::assertNull($user?->birthday);
        self::assertNull($user?->secretPhraseHash);
        self::assertNotNull($user);
        $saved = $repository->save($user);
        self::assertNull($saved->birthday);
        self::assertNull($saved->secretPhraseHash);

        $migration->down();
        self::assertSame('character varying(255)', $this->formattedColumnType('users', 'email'));
        self::assertSame('character varying(255)', $this->formattedColumnType('users', 'name'));
        self::assertFalse(Schema::hasColumn('users', UserModel::FIELD_VERSION));
        self::assertSame('Legacy', DB::table('users')->where('id', $id)->value('name'));
    }

    #[Test]
    public function it_enforces_case_insensitive_email_uniqueness(): void
    {
        $this->migrateLaravelUsersTable();
        self::assertSame('citext', $this->formattedColumnType('users', 'email'));
        DB::table('users')->insert(['nickname' => 'one', 'email' => 'Case@Example.com', 'password' => 'hash']);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('users')->insert(['nickname' => 'two', 'email' => 'case@example.com', 'password' => 'hash']);
    }

    #[Test]
    public function it_reports_legacy_email_collisions_before_changing_the_schema(): void
    {
        $base = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $base->up();
        DB::table('users')->insert([
            ['name' => 'one', 'email' => 'Case@Example.com', 'password' => 'hash'],
            ['name' => 'two', 'email' => 'case@example.com', 'password' => 'hash'],
        ]);

        $migration = require database_path('migrations/2026_10_19_210600_reshape_users_and_create_user_preferences.php');
        try {
            $migration->up();
            self::fail('Email collision must be resolved explicitly.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('resolve existing email duplicates', $exception->getMessage());
        }

        self::assertTrue(Schema::hasColumn('users', 'name'));
        self::assertSame(2, DB::table('users')->count());
    }


    #[Test]
    public function it_purges_existing_user_data_on_postgresql(): void
    {
        $this->migrateLaravelUsersTable();
        $id = DB::table('users')->insertGetId(['nickname' => 'legacy', 'email' => 'Case@Example.com', 'password' => self::PASSWORD_HASH]);
        DB::table('user_preferences')->insert(['user_id' => $id]);
        DB::table('sessions')->insert(['id' => 'session', 'user_id' => $id, 'payload' => 'data', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => 'CASE@example.com', 'token' => 'token']);

        $repository = new EloquentUserRepository;
        $user = $repository->findById($id);
        self::assertNotNull($user);
        $repository->forceDelete($user);

        foreach (['users', 'user_preferences', 'sessions', 'password_reset_tokens'] as $table) {
            self::assertSame(0, DB::table($table)->count());
        }
    }


    #[Test]
    public function it_keeps_legacy_nicknames_that_differ_only_by_case(): void
    {
        $base = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $base->up();
        DB::table('users')->insert([
            ['name' => 'Anton', 'email' => 'one@example.com', 'password' => 'hash'],
            ['name' => 'anton', 'email' => 'two@example.com', 'password' => 'hash'],
        ]);

        $migration = require database_path('migrations/2026_10_19_210600_reshape_users_and_create_user_preferences.php');
        $migration->up();

        self::assertSame(['Anton', 'anton'], DB::table('users')->orderBy('id')->pluck('nickname')->all());
    }

    #[Test]
    public function it_reports_legacy_nickname_collisions_without_renaming_or_removing_users(): void
    {
        $base = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $base->up();
        DB::table('users')->insert([
            ['name' => 'Anton', 'email' => 'one@example.com', 'password' => 'hash'],
            ['name' => 'Anton', 'email' => 'two@example.com', 'password' => 'hash'],
        ]);

        $migration = require database_path('migrations/2026_10_19_210600_reshape_users_and_create_user_preferences.php');
        try {
            $migration->up();
            self::fail('Nickname collision must be resolved explicitly.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('resolve existing duplicate user names', $exception->getMessage());
        }

        self::assertSame(['Anton', 'Anton'], DB::table('users')->orderBy('id')->pluck('name')->all());
        self::assertSame('character varying(255)', $this->formattedColumnType('users', 'email'));
        self::assertFalse(Schema::hasColumn('users', 'nickname'));
    }


    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = 'users_migration_'.bin2hex(random_bytes(8));
        $this->previousConnection = (string) config('database.default');
        config([
            'database.connections.'.self::CONNECTION => $this->connectionConfig('public'),
        ]);
        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->statement(
            'drop schema if exists '.$this->schema.' cascade',
        );
        DB::connection(self::CONNECTION)->statement(
            'create schema '.$this->schema,
        );
        $this->schemaReady = true;

        config([
            'database.connections.'.self::CONNECTION.'.search_path' => $this->schema.', public',
            'database.default' => self::CONNECTION,
        ]);
        DB::purge(self::CONNECTION);
        DB::reconnect(self::CONNECTION);
    }

    protected function tearDown(): void
    {
        if ($this->schemaReady) {
            DB::connection(self::CONNECTION)->statement(
                'drop schema if exists '.$this->schema.' cascade',
            );
        }

        config(['database.default' => $this->previousConnection]);
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }


    private function connectionConfig(string $searchPath): array
    {
        $config = config('database.connections.'.self::BASE_CONNECTION);

        return [...$config, 'search_path' => $searchPath];
    }

    private function registerUser(string $nickname, string $email): User
    {
        return User::register(
            nickname: Nickname::createFromString($nickname),
            email: Email::createFromString($email),
            password: PasswordHash::createFromHash(self::PASSWORD_HASH),
            secretPhraseHash: SecretPhraseHash::createFromHash('phrase-hash'),
            birthday: null,
            birthdayPolicy: new BirthdayPolicy,
        );
    }

    private function migrateLaravelUsersTable(): void
    {
        $base = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $base->up();

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
            [$this->schema, $table, $column],
        );

        self::assertNotNull($row);

        return (string) $row->type;
    }
}
