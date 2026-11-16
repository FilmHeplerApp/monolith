<?php

use App\Domain\Shared\ValueObjects\Embedding;
use App\Infrastructure\Persistence\Eloquent\Models\User;
use App\Infrastructure\Persistence\Eloquent\Models\UserPreference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string STATUS_VALID = 'valid';
    private const string ROLE_USER = 'user';
    private const string DEFAULT_TIMEZONE = 'Europe/Moscow';
    private const string DEFAULT_THEME = 'system';
    private const string DEFAULT_LANGUAGE = 'ru';
    private const string DEFAULT_INTERFACE_TYPE = 'swipe';
    private const int NICKNAME_MAX_LENGTH = 32;
    private const int LEGACY_NICKNAME_MAX_LENGTH = 255;
    private const int INITIAL_VERSION = 1;
    private const array STATUSES = [
        'valid',
        'blocked',
    ];
    private const array ROLES = [
        'user',
        'moderator',
        'admin',
    ];
    private const array THEMES = [
        'dark',
        'light',
        'system',
    ];
    private const array LANGUAGES = [
        'ru',
        'en',
    ];
    private const array INTERFACE_TYPES = [
        'swipe',
        'list',
    ];


    public function up(): void
    {
        $duplicateEmail = DB::table(User::TABLE_NAME)
            ->selectRaw('lower(email) as normalized_email')
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($duplicateEmail) {
            throw new RuntimeException('Cannot make users.email case-insensitive: resolve existing email duplicates that differ only by case before migrating.');
        }

        $duplicateNickname = DB::table(User::TABLE_NAME)
            ->select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($duplicateNickname) {
            throw new RuntimeException('Cannot create unique nicknames: resolve existing duplicate user names before migrating.');
        }

        $longNickname = DB::table(User::TABLE_NAME)
            ->whereRaw('char_length(name) > ?', [self::NICKNAME_MAX_LENGTH])
            ->exists();

        if ($longNickname) {
            throw new RuntimeException(sprintf('Cannot limit nicknames to %d characters: shorten existing user names before migrating.', self::NICKNAME_MAX_LENGTH));
        }

        DB::statement('create extension if not exists citext with schema public');
        DB::statement('alter table users alter column email type public.citext using email::public.citext');

        Schema::table(User::TABLE_NAME, function (Blueprint $table): void {
            $table->renameColumn('name', User::FIELD_NICKNAME);
        });

        Schema::table(User::TABLE_NAME, function (Blueprint $table): void {
            $table->string(User::FIELD_NICKNAME, self::NICKNAME_MAX_LENGTH)->change();
            $table->dropColumn(['email_verified_at', 'remember_token']);
            $table->string(User::FIELD_AVATAR_KEY)->nullable();
            $table->vector(User::FIELD_EMBEDDING, Embedding::DIMENSION)->vectorIndex()->nullable();
            $table->enum(User::FIELD_STATUS, self::STATUSES)->default(self::STATUS_VALID);
            $table->boolean(User::FIELD_IS_ACTIVE)->default(true);
            $table->enum(User::FIELD_ROLE, self::ROLES)->default(self::ROLE_USER);
            $table->timestamp(User::FIELD_LAST_ACTIVE_AT)->nullable();
            $table->date(User::FIELD_BIRTHDAY)->nullable();
            $table->string(User::FIELD_SECRET_PHRASE_HASH)->nullable();
            $table->unsignedInteger(User::FIELD_VERSION)->default(self::INITIAL_VERSION);
            $table->softDeletes();
        });

        DB::statement(sprintf(
            'create unique index %s on %s (%s)',
            User::NICKNAME_UNIQUE_INDEX,
            User::TABLE_NAME,
            User::FIELD_NICKNAME,
        ));

        Schema::create(UserPreference::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->foreignId(UserPreference::FIELD_USER_ID)
                ->unique()
                ->constrained(User::TABLE_NAME)
                ->cascadeOnDelete();
            $table->string(UserPreference::FIELD_TIMEZONE)->default(self::DEFAULT_TIMEZONE);
            $table->enum(UserPreference::FIELD_THEME, self::THEMES)->default(self::DEFAULT_THEME);
            $table->enum(UserPreference::FIELD_LANGUAGE, self::LANGUAGES)->default(self::DEFAULT_LANGUAGE);
            $table->enum(UserPreference::FIELD_INTERFACE_TYPE, self::INTERFACE_TYPES)->default(self::DEFAULT_INTERFACE_TYPE);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        DB::statement('alter table users alter column email type varchar(255) using email::text');

        Schema::dropIfExists(UserPreference::TABLE_NAME);

        DB::statement(sprintf(
            'drop index if exists %s',
            User::NICKNAME_UNIQUE_INDEX,
        ));

        Schema::table(User::TABLE_NAME, function (Blueprint $table): void {
            $table->dropColumn([
                User::FIELD_AVATAR_KEY,
                User::FIELD_EMBEDDING,
                User::FIELD_STATUS,
                User::FIELD_IS_ACTIVE,
                User::FIELD_ROLE,
                User::FIELD_LAST_ACTIVE_AT,
                User::FIELD_BIRTHDAY,
                User::FIELD_SECRET_PHRASE_HASH,
                User::FIELD_VERSION,
                User::FIELD_DELETED_AT,
            ]);
            $table->string(User::FIELD_NICKNAME, self::LEGACY_NICKNAME_MAX_LENGTH)->change();
        });

        Schema::table(User::TABLE_NAME, function (Blueprint $table): void {
            $table->renameColumn(User::FIELD_NICKNAME, 'name');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
        });
    }
};
