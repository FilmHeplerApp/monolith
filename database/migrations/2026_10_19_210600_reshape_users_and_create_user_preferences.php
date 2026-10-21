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
        Schema::table(User::TABLE_NAME, function (Blueprint $table): void {
            $table->renameColumn('name', User::FIELD_NICKNAME);
        });

        Schema::table(User::TABLE_NAME, function (Blueprint $table): void {
            $table->dropColumn(['email_verified_at', 'remember_token']);
            $table->string(User::FIELD_AVATAR_KEY)->nullable();
            $table->vector(User::FIELD_EMBEDDING, Embedding::DIMENSION)->vectorIndex()->nullable();
            $table->enum(User::FIELD_STATUS, self::STATUSES)->default(self::STATUS_VALID);
            $table->boolean(User::FIELD_IS_ACTIVE)->default(true);
            $table->enum(User::FIELD_ROLE, self::ROLES)->default(self::ROLE_USER);
            $table->timestamp(User::FIELD_LAST_ACTIVE_AT)->nullable();
            $table->date(User::FIELD_BIRTHDAY);
            $table->string(User::FIELD_SECRET_PHRASE_HASH);
            $table->softDeletes();
        });

        DB::statement(sprintf(
            'create unique index %s on %s (lower(%s))',
            User::NICKNAME_LOWER_UNIQUE_INDEX,
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
        Schema::dropIfExists(UserPreference::TABLE_NAME);

        DB::statement(sprintf(
            'drop index if exists %s',
            User::NICKNAME_LOWER_UNIQUE_INDEX,
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
                User::FIELD_DELETED_AT,
            ]);
        });

        Schema::table(User::TABLE_NAME, function (Blueprint $table): void {
            $table->renameColumn(User::FIELD_NICKNAME, 'name');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
        });
    }
};
