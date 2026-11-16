<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Shared\ValueObjects\Embedding;
use App\Domain\Users\Entities\User;
use App\Domain\Users\Entities\UserPreference;
use App\Domain\Users\Enums\User\UserRole;
use App\Domain\Users\Enums\User\UserStatus;
use App\Domain\Users\Enums\UserPreference\InterfaceType;
use App\Domain\Users\Enums\UserPreference\Language;
use App\Domain\Users\Enums\UserPreference\Theme;
use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\Exceptions\UserNotFoundException;
use App\Domain\Users\Repositories\UserPreferenceRepository;
use App\Domain\Users\Repositories\UserRepository;
use App\Domain\Users\Services\BirthdayPolicy;
use App\Domain\Users\ValueObjects\User\Birthday;
use App\Domain\Users\ValueObjects\User\Email;
use App\Domain\Users\ValueObjects\User\Nickname;
use App\Domain\Users\ValueObjects\User\PasswordHash;
use App\Domain\Users\ValueObjects\User\SecretPhraseHash;
use App\Domain\Users\ValueObjects\UserPreference\Timezone;
use App\Infrastructure\Persistence\Eloquent\Models\User as UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserPreference as UserPreferenceModel;
use App\Infrastructure\Persistence\Eloquent\Repositories\Users\EloquentUserPreferenceRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\Users\EloquentUserRepository;
use DateTimeImmutable;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class EloquentUserRepositoryTest extends TestCase
{
    private const string NICKNAME = 'anton';
    private const string EMAIL = 'anton@example.com';
    private const string PASSWORD_HASH = '$2y$04$Z2UUGoTmGSItu9967u1JNOEGIZMm4VXC1S1qTBrZIkJ/cv5vHNMuq';
    private const string PHRASE_HASH = 'phrase-hash';
    private const string AVATAR_KEY = 'users/avatars/018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f13/avatar.webp';
    private const string BIRTHDAY = '1994-03-12';


    private UserRepository $users;
    private UserPreferenceRepository $preferences;


    #[Test]
    public function it_is_bound_to_the_eloquent_repositories(): void
    {
        self::assertInstanceOf(EloquentUserRepository::class, $this->users);
        self::assertInstanceOf(EloquentUserPreferenceRepository::class, $this->preferences);
    }

    #[Test]
    public function it_saves_and_finds_a_user_by_id_email_and_nickname(): void
    {
        $saved = $this->users->save($this->registerUser());

        self::assertNotNull($saved->id);
        self::assertSame(self::NICKNAME, $saved->nickname->getValue());
        self::assertSame(self::EMAIL, $saved->email->getValue());
        self::assertSame(self::PASSWORD_HASH, $saved->password->getValue());
        self::assertNull($saved->avatarKey);
        self::assertNull($saved->embedding);
        self::assertSame(UserStatus::VALID, $saved->status);
        self::assertTrue($saved->isActive);
        self::assertSame(UserRole::USER, $saved->role);
        self::assertNull($saved->lastActiveAt);
        self::assertSame(self::BIRTHDAY, $saved->birthday?->getValue()->format('Y-m-d'));
        self::assertSame(self::PHRASE_HASH, $saved->secretPhraseHash?->getValue());
        self::assertNotNull($saved->createdAt);
        self::assertNull($saved->deletedAt);

        $byId = $this->users->findById($saved->id);
        $byEmail = $this->users->findByEmail(Email::createFromString(self::EMAIL));
        $byNickname = $this->users->findByNickname(Nickname::createFromString(self::NICKNAME));

        self::assertSame($saved->id, $byId?->id);
        self::assertSame($saved->id, $byEmail?->id);
        self::assertSame($saved->id, $byNickname?->id);
    }

    #[Test]
    public function it_saves_and_finds_a_registered_user_without_a_birthday(): void
    {
        $saved = $this->users->save($this->registerUser(birthday: null));

        self::assertNull($saved->birthday);
        self::assertNull($this->users->findById((int) $saved->id)?->birthday);
    }

    #[Test]
    public function it_finds_a_nickname_regardless_of_case_and_keeps_the_entered_form(): void
    {
        $saved = $this->users->save($this->registerUser(nickname: 'Anton'));

        $found = $this->users->findByNickname(Nickname::createFromString('aNtOn'));

        self::assertSame($saved->id, $found?->id);
        self::assertSame('Anton', $found?->nickname->getValue());
    }

    #[Test]
    public function it_rejects_a_nickname_that_differs_only_by_case(): void
    {
        $this->users->save($this->registerUser(nickname: 'Anton'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->users->save($this->registerUser(
            nickname: 'anton',
            email: 'other@example.com',
        ));
    }

    #[Test]
    public function it_updates_an_existing_user(): void
    {
        $saved = $this->users->save($this->registerUser());
        $renamed = $this->restoreUser(
            id: $saved->id,
            nickname: 'mira',
            role: UserRole::ADMIN,
        );

        $updated = $this->users->save($renamed);

        self::assertSame($saved->id, $updated->id);
        self::assertSame('mira', $updated->nickname->getValue());
        self::assertSame(UserRole::ADMIN, $updated->role);
        self::assertSame('mira', $this->users->findById($saved->id)?->nickname->getValue());
    }

    #[Test]
    public function it_rejects_an_update_of_an_unknown_user(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->users->save($this->restoreUser(id: 999));
    }

    #[Test]
    public function it_writes_and_reads_avatar_and_embedding(): void
    {
        $registered = $this->users->save($this->registerUser());

        $updated = $this->users->save($this->restoreUser(
            id: $registered->id,
            avatarKey: self::AVATAR_KEY,
            embedding: $this->embedding(),
        ));
        $found = $this->users->findById($registered->id);

        self::assertSame(self::AVATAR_KEY, $updated->avatarKey);
        self::assertSame($this->embedding()->getVector(), $updated->embedding?->getVector());
        self::assertSame(self::AVATAR_KEY, $found?->avatarKey);
        self::assertSame($this->embedding()->getVector(), $found?->embedding?->getVector());
    }

    #[Test]
    public function it_restores_a_stored_user_whose_birthday_is_outside_the_current_policy(): void
    {
        $registered = $this->users->save($this->registerUser());
        $storedBirthday = sprintf('%d-05-01', BirthdayPolicy::MIN_YEAR - 10);
        UserModel::query()
            ->whereKey($registered->id)
            ->update([UserModel::FIELD_BIRTHDAY => $storedBirthday]);

        $found = $this->users->findById($registered->id);

        self::assertNotNull($found);
        self::assertSame($storedBirthday, $found->birthday?->getValue()->format('Y-m-d'));

        $resaved = $this->users->save($found);

        self::assertSame($storedBirthday, $resaved->birthday?->getValue()->format('Y-m-d'));
    }

    #[Test]
    public function it_writes_and_reads_last_active_at(): void
    {
        $lastActiveAt = new DateTimeImmutable('2026-10-20 18:15:00');

        $registered = $this->users->save($this->registerUser());
        $saved = $this->users->save($this->restoreUser(id: $registered->id, lastActiveAt: $lastActiveAt));
        $found = $this->users->findById($saved->id);

        self::assertNotNull($found?->lastActiveAt);
        self::assertSame(
            $lastActiveAt->format('Y-m-d H:i:s'),
            $found->lastActiveAt->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function it_soft_deletes_a_user_and_keeps_preferences(): void
    {
        $saved = $this->users->save($this->registerUser());
        $this->preferences->save($this->makePreference($saved->id));

        $this->users->delete($saved);

        self::assertNull($this->users->findById($saved->id));
        self::assertNull($this->users->findByEmail(Email::createFromString(self::EMAIL)));
        self::assertNotNull($this->preferences->findByUserId($saved->id));
        $this->assertSoftDeleted(UserModel::TABLE_NAME, [
            UserModel::FIELD_ID => $saved->id,
        ]);
    }

    #[Test]
    public function it_force_deletes_a_soft_deleted_user_and_preferences(): void
    {
        $saved = $this->users->save($this->registerUser());
        $this->preferences->save($this->makePreference($saved->id));
        $this->users->delete($saved);

        $this->users->forceDelete($saved);

        $this->assertDatabaseMissing(UserModel::TABLE_NAME, [
            UserModel::FIELD_ID => $saved->id,
        ]);
        $this->assertDatabaseMissing(UserPreferenceModel::TABLE_NAME, [
            UserPreferenceModel::FIELD_USER_ID => $saved->id,
        ]);
    }

    #[Test]
    public function it_force_deletes_a_user_without_a_prior_soft_delete(): void
    {
        $saved = $this->users->save($this->registerUser());
        $this->preferences->save($this->makePreference($saved->id));

        $this->users->forceDelete($saved);

        self::assertNull($this->users->findById($saved->id));
        self::assertNull($this->preferences->findByUserId($saved->id));
        $this->assertDatabaseMissing(UserModel::TABLE_NAME, [
            UserModel::FIELD_ID => $saved->id,
        ]);
        $this->assertDatabaseMissing(UserPreferenceModel::TABLE_NAME, [
            UserPreferenceModel::FIELD_USER_ID => $saved->id,
        ]);
    }

    #[Test]
    public function it_rejects_force_delete_of_an_unsaved_user(): void
    {
        $this->expectException(InvalidUserValueException::class);

        $this->users->forceDelete($this->registerUser());
    }

    #[Test]
    public function it_rejects_delete_of_an_unsaved_user(): void
    {
        $this->expectException(InvalidUserValueException::class);

        $this->users->delete($this->registerUser());
    }

    #[Test]
    public function it_rejects_force_delete_of_an_unknown_user(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->users->forceDelete($this->restoreUser(id: 999));
    }

    #[Test]
    public function it_saves_and_finds_preferences(): void
    {
        $saved = $this->users->save($this->registerUser());

        $preference = $this->preferences->save($this->makePreference($saved->id));
        $again = $this->preferences->save($this->makePreference(
            userId: $saved->id,
            theme: Theme::DARK,
            language: Language::EN,
            interfaceType: InterfaceType::LIST,
        ));

        self::assertNotNull($preference->id);
        self::assertSame($preference->id, $again->id);
        self::assertSame($saved->id, $again->userId);
        self::assertSame(Timezone::DEFAULT, $again->timezone->getValue());
        self::assertSame(Theme::DARK, $again->theme);
        self::assertSame(Language::EN, $again->language);
        self::assertSame(InterfaceType::LIST, $again->interfaceType);
        self::assertSame(Theme::DARK, $this->preferences->findByUserId($saved->id)?->theme);
    }

    #[Test]
    public function it_rejects_preferences_for_a_missing_user(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->preferences->save($this->makePreference(42));
    }


    #[Test]
    public function it_purges_sessions_and_reset_tokens_without_touching_other_accounts(): void
    {
        $user = $this->users->save($this->registerUser());
        $other = $this->users->save($this->registerUser(nickname: 'other', email: 'other@example.com'));
        DB::table('sessions')->insert([
            ['id' => 'target-session', 'user_id' => $user->id],
            ['id' => 'other-session', 'user_id' => $other->id],
            ['id' => 'guest-session', 'user_id' => null],
        ]);
        DB::table('password_reset_tokens')->insert([
            ['email' => strtoupper(self::EMAIL), 'token' => 'target-token'],
            ['email' => 'other@example.com', 'token' => 'other-token'],
        ]);
        $this->users->delete($user);
        $this->users->forceDelete($user);

        self::assertSame(['guest-session', 'other-session'], DB::table('sessions')->orderBy('id')->pluck('id')->all());
        self::assertSame(['other@example.com'], DB::table('password_reset_tokens')->pluck('email')->all());
        self::assertNotNull($this->users->findById($other->id));
    }

    #[Test]
    public function it_rolls_back_all_cleanup_when_user_deletion_fails(): void
    {
        $user = $this->users->save($this->registerUser());
        $this->preferences->save($this->makePreference($user->id));
        DB::table('sessions')->insert(['id' => 'session', 'user_id' => $user->id]);
        DB::table('password_reset_tokens')->insert(['email' => self::EMAIL, 'token' => 'token']);
        DB::unprepared("CREATE TRIGGER reject_user_delete BEFORE DELETE ON users BEGIN SELECT RAISE(ABORT, 'delete failed'); END");

        try {
            $this->users->forceDelete($user);
            self::fail('Deletion must fail.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('delete failed', $exception->getMessage());
        }

        self::assertNotNull($this->users->findById($user->id));
        self::assertNotNull($this->preferences->findByUserId($user->id));
        self::assertSame(1, DB::table('sessions')->count());
        self::assertSame(1, DB::table('password_reset_tokens')->count());
    }


    #[Test]
    public function it_rolls_back_cleanup_when_a_model_observer_cancels_deletion(): void
    {
        $user = $this->users->save($this->registerUser());
        $this->preferences->save($this->makePreference($user->id));
        DB::table('sessions')->insert(['id' => 'session', 'user_id' => $user->id]);
        DB::table('password_reset_tokens')->insert(['email' => self::EMAIL, 'token' => 'token']);
        $dispatcher = UserModel::getEventDispatcher();
        UserModel::setEventDispatcher(new Dispatcher($this->app));
        UserModel::deleting(static fn (): bool => false);

        try {
            try {
                $this->users->forceDelete($user);
                self::fail('Cancelled deletion must not commit cleanup.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('deletion was cancelled', $exception->getMessage());
            }
        } finally {
            UserModel::setEventDispatcher($dispatcher);
        }

        self::assertNotNull($this->users->findById($user->id));
        self::assertNotNull($this->preferences->findByUserId($user->id));
        self::assertSame(1, DB::table('sessions')->count());
        self::assertSame(1, DB::table('password_reset_tokens')->count());
    }


    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->users = $this->app->make(UserRepository::class);
        $this->preferences = $this->app->make(UserPreferenceRepository::class);
    }


    private function registerUser(
        string  $nickname = self::NICKNAME,
        string  $email = self::EMAIL,
        ?string $birthday = self::BIRTHDAY,
    ): User {
        return User::register(
            nickname: Nickname::createFromString($nickname),
            email: Email::createFromString($email),
            password: PasswordHash::createFromHash(self::PASSWORD_HASH),
            secretPhraseHash: SecretPhraseHash::createFromHash(self::PHRASE_HASH),
            birthday: $birthday === null ? null : Birthday::createFromDateTime(new DateTimeImmutable($birthday)),
            birthdayPolicy: new BirthdayPolicy,
        );
    }

    private function restoreUser(
        int                $id,
        string             $nickname = self::NICKNAME,
        ?string            $avatarKey = null,
        ?Embedding         $embedding = null,
        UserRole           $role = UserRole::USER,
        ?DateTimeImmutable $lastActiveAt = null,
    ): User {
        return User::reconstitute(
            id: $id,
            nickname: Nickname::createFromString($nickname),
            email: Email::createFromString(self::EMAIL),
            password: PasswordHash::createFromHash(self::PASSWORD_HASH),
            avatarKey: $avatarKey,
            embedding: $embedding,
            status: UserStatus::VALID,
            isActive: true,
            role: $role,
            lastActiveAt: $lastActiveAt,
            birthday: Birthday::createFromDateTime(new DateTimeImmutable(self::BIRTHDAY)),
            secretPhraseHash: SecretPhraseHash::createFromHash(self::PHRASE_HASH),
        );
    }

    private function makePreference(
        int           $userId,
        Theme         $theme = Theme::SYSTEM,
        Language      $language = Language::RU,
        InterfaceType $interfaceType = InterfaceType::SWIPE,
    ): UserPreference {
        return new UserPreference(
            id: null,
            userId: $userId,
            timezone: Timezone::createFromString(Timezone::DEFAULT),
            theme: $theme,
            language: $language,
            interfaceType: $interfaceType,
        );
    }

    private function embedding(): Embedding
    {
        $embedding = Embedding::createFromArray(array_fill(0, Embedding::DIMENSION, 0.5));

        self::assertInstanceOf(Embedding::class, $embedding);

        return $embedding;
    }

    private function createTables(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
        });
        Schema::dropIfExists(UserPreferenceModel::TABLE_NAME);
        Schema::dropIfExists(UserModel::TABLE_NAME);

        Schema::create(UserModel::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->string(UserModel::FIELD_NICKNAME);
            $table->string(UserModel::FIELD_EMAIL)->unique();
            $table->string(UserModel::FIELD_PASSWORD);
            $table->string(UserModel::FIELD_AVATAR_KEY)->nullable();
            $table->text(UserModel::FIELD_EMBEDDING)->nullable();
            $table->string(UserModel::FIELD_STATUS)->default(UserStatus::VALID->value);
            $table->boolean(UserModel::FIELD_IS_ACTIVE)->default(true);
            $table->string(UserModel::FIELD_ROLE)->default(UserRole::USER->value);
            $table->timestamp(UserModel::FIELD_LAST_ACTIVE_AT)->nullable();
            $table->date(UserModel::FIELD_BIRTHDAY)->nullable();
            $table->string(UserModel::FIELD_SECRET_PHRASE_HASH)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(
                [new Expression('lower('.UserModel::FIELD_NICKNAME.')')],
                UserModel::NICKNAME_LOWER_UNIQUE_INDEX,
            );
        });

        Schema::create(UserPreferenceModel::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->foreignId(UserPreferenceModel::FIELD_USER_ID)
                ->unique()
                ->constrained(UserModel::TABLE_NAME)
                ->cascadeOnDelete();
            $table->string(UserPreferenceModel::FIELD_TIMEZONE);
            $table->string(UserPreferenceModel::FIELD_THEME);
            $table->string(UserPreferenceModel::FIELD_LANGUAGE);
            $table->string(UserPreferenceModel::FIELD_INTERFACE_TYPE);
            $table->timestamps();
        });
    }
}
