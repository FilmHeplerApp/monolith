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
use App\Domain\Users\Exceptions\UserAlreadyExistsException;
use App\Domain\Users\Exceptions\UserConcurrentModificationException;
use App\Domain\Users\Exceptions\UserNotFoundException;
use App\Domain\Users\Repositories\UserPreferenceRepository;
use App\Domain\Users\Repositories\UserRepository;
use App\Domain\Users\Services\BirthdayPolicy;
use App\Domain\Users\Services\TimezonePolicy;
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
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->expectException(UserAlreadyExistsException::class);
        $this->expectExceptionMessage('Nickname is already taken.');

        $this->users->save($this->registerUser(
            nickname: 'anton',
            email: 'other@example.com',
        ));
    }

    #[Test]
    public function it_rejects_a_taken_email(): void
    {
        $this->users->save($this->registerUser());

        $this->expectException(UserAlreadyExistsException::class);
        $this->expectExceptionMessage('Email is already taken.');

        $this->users->save($this->registerUser(
            nickname: 'other',
            email: strtoupper(self::EMAIL),
        ));
    }

    #[Test]
    public function it_rejects_changing_the_nickname_to_a_taken_one(): void
    {
        $this->users->save($this->registerUser());
        $other = $this->users->save($this->registerUser(nickname: 'other', email: 'other@example.com'));

        $this->expectException(UserAlreadyExistsException::class);
        $this->expectExceptionMessage('Nickname is already taken.');

        $this->users->save($other->changeNickname(Nickname::createFromString(strtoupper(self::NICKNAME))));
    }

    #[Test]
    public function it_reports_whether_an_email_or_nickname_is_taken(): void
    {
        $this->users->save($this->registerUser(nickname: 'Anton'));

        self::assertTrue($this->users->existsByEmail(Email::createFromString(strtoupper(self::EMAIL))));
        self::assertTrue($this->users->existsByNickname(Nickname::createFromString('aNtOn')));
        self::assertFalse($this->users->existsByEmail(Email::createFromString('free@example.com')));
        self::assertFalse($this->users->existsByNickname(Nickname::createFromString('free')));
    }

    #[Test]
    public function it_keeps_the_email_and_nickname_of_a_soft_deleted_user_taken(): void
    {
        $deleted = $this->users->save($this->registerUser());
        $this->users->delete($deleted);

        self::assertNull($this->users->findByEmail(Email::createFromString(self::EMAIL)));
        self::assertNull($this->users->findByNickname(Nickname::createFromString(self::NICKNAME)));
        self::assertTrue($this->users->existsByEmail(Email::createFromString(self::EMAIL)));
        self::assertTrue($this->users->existsByNickname(Nickname::createFromString(self::NICKNAME)));

        try {
            $this->users->save($this->registerUser(nickname: 'other'));
            self::fail('The email of a soft-deleted user must stay taken.');
        } catch (UserAlreadyExistsException $exception) {
            self::assertSame('Email is already taken.', $exception->getMessage());
        }

        $this->expectException(UserAlreadyExistsException::class);
        $this->expectExceptionMessage('Nickname is already taken.');

        $this->users->save($this->registerUser(email: 'other@example.com'));
    }

    #[Test]
    public function it_updates_an_existing_user_and_increments_the_version(): void
    {
        $saved = $this->users->save($this->registerUser());

        $updated = $this->users->save($saved->changeNickname(Nickname::createFromString('mira')));

        self::assertSame(1, $saved->version);
        self::assertSame($saved->id, $updated->id);
        self::assertSame(2, $updated->version);
        self::assertSame('mira', $updated->nickname->getValue());
        self::assertSame('mira', $this->users->findById((int) $saved->id)?->nickname->getValue());
    }

    #[Test]
    public function it_rejects_a_stale_update_without_overwriting_the_newer_state(): void
    {
        $saved = $this->users->save($this->registerUser());
        $loadedByA = $this->users->findById((int) $saved->id);
        $loadedByB = $this->users->findById((int) $saved->id);
        self::assertNotNull($loadedByA);
        self::assertNotNull($loadedByB);

        $this->users->save($loadedByB->changeAvatar(self::AVATAR_KEY));

        try {
            $this->users->save($loadedByA->changeNickname(Nickname::createFromString('mira')));
            self::fail('A stale update must be rejected.');
        } catch (UserConcurrentModificationException $exception) {
            self::assertSame(
                sprintf('User %d was changed by another process; reload it and retry.', $saved->id),
                $exception->getMessage(),
            );
        }

        $stored = $this->users->findById((int) $saved->id);
        self::assertSame(self::AVATAR_KEY, $stored?->avatarKey);
        self::assertSame(self::NICKNAME, $stored?->nickname->getValue());
        self::assertSame(2, $stored?->version);
    }

    #[Test]
    public function it_rejects_an_update_of_an_unknown_user(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->users->save($this->restoreUser(id: 999));
    }

    #[Test]
    public function it_rejects_an_update_of_a_soft_deleted_user(): void
    {
        $saved = $this->users->save($this->registerUser());
        $this->users->delete($saved);

        $this->expectException(UserNotFoundException::class);

        $this->users->save($saved->changeNickname(Nickname::createFromString('mira')));
    }

    #[Test]
    public function it_writes_and_reads_avatar_and_embedding(): void
    {
        $registered = $this->users->save($this->registerUser());

        $updated = $this->users->save(
            $registered
                ->changeAvatar(self::AVATAR_KEY)
                ->updateEmbedding($this->embedding()),
        );
        $found = $this->users->findById((int) $registered->id);

        self::assertSame(self::AVATAR_KEY, $updated->avatarKey);
        self::assertSame($this->embedding()->getVector(), $updated->embedding?->getVector());
        self::assertSame(self::AVATAR_KEY, $found?->avatarKey);
        self::assertSame($this->embedding()->getVector(), $found?->embedding?->getVector());
    }

    #[Test]
    #[DataProvider('birthdaysOutsideTheCurrentPolicy')]
    public function it_restores_a_stored_user_whose_birthday_is_outside_the_current_policy(string $storedBirthday): void
    {
        $registered = $this->users->save($this->registerUser());
        UserModel::query()
            ->whereKey($registered->id)
            ->update([UserModel::FIELD_BIRTHDAY => $storedBirthday]);

        $found = $this->users->findById((int) $registered->id);

        self::assertNotNull($found);
        self::assertSame($storedBirthday, $found->birthday?->getValue()->format('Y-m-d'));

        $resaved = $this->users->save($found);

        self::assertSame($storedBirthday, $resaved->birthday?->getValue()->format('Y-m-d'));
    }

    public static function birthdaysOutsideTheCurrentPolicy(): iterable
    {
        yield 'before the minimum year' => [sprintf('%d-05-01', BirthdayPolicy::MIN_YEAR - 10)];
        yield 'in the future for the server clock' => [(new DateTimeImmutable('tomorrow'))->format('Y-m-d')];
    }

    #[Test]
    public function it_touches_last_active_at_without_changing_the_version_or_updated_at(): void
    {
        $lastActiveAt = new DateTimeImmutable('2026-10-20 18:15:00');
        $previousUpdatedAt = '2026-01-01 10:00:00';
        $registered = $this->users->save($this->registerUser());
        UserModel::query()
            ->whereKey($registered->id)
            ->toBase()
            ->update([UserModel::FIELD_UPDATED_AT => $previousUpdatedAt]);

        $this->users->touchLastActiveAt((int) $registered->id, $lastActiveAt);
        $found = $this->users->findById((int) $registered->id);

        self::assertSame(
            $lastActiveAt->format('Y-m-d H:i:s'),
            $found?->lastActiveAt?->format('Y-m-d H:i:s'),
        );
        self::assertSame($registered->version, $found?->version);
        self::assertSame($previousUpdatedAt, $found?->updatedAt?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_does_not_overwrite_last_active_at_when_saving_a_profile_change(): void
    {
        $lastActiveAt = new DateTimeImmutable('2026-10-20 18:15:00');
        $registered = $this->users->save($this->registerUser());

        $this->users->touchLastActiveAt((int) $registered->id, $lastActiveAt);
        $updated = $this->users->save($registered->changeNickname(Nickname::createFromString('mira')));

        self::assertSame('mira', $updated->nickname->getValue());
        self::assertSame(
            $lastActiveAt->format('Y-m-d H:i:s'),
            $updated->lastActiveAt?->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function it_rejects_touching_an_unknown_or_soft_deleted_user(): void
    {
        $deleted = $this->users->save($this->registerUser());
        $this->users->delete($deleted);

        foreach ([999, (int) $deleted->id] as $id) {
            try {
                $this->users->touchLastActiveAt($id, new DateTimeImmutable);
                self::fail('Touching a missing user must fail.');
            } catch (UserNotFoundException $exception) {
                self::assertSame(sprintf('User %d was not found.', $id), $exception->getMessage());
            }
        }
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
    public function it_restores_and_resaves_preferences_with_a_renamed_timezone(): void
    {
        $saved = $this->users->save($this->registerUser());
        $this->preferences->save($this->makePreference((int) $saved->id));
        UserPreferenceModel::query()
            ->where(UserPreferenceModel::FIELD_USER_ID, $saved->id)
            ->update([UserPreferenceModel::FIELD_TIMEZONE => 'Europe/Kiev']);

        $found = $this->preferences->findByUserId((int) $saved->id);

        self::assertSame('Europe/Kiev', $found?->timezone->getValue());
        self::assertNotNull($found);
        self::assertSame('Europe/Kiev', $this->preferences->save($found)->timezone->getValue());
    }

    #[Test]
    public function it_saves_a_changed_timezone(): void
    {
        $saved = $this->users->save($this->registerUser());
        $preference = $this->preferences->save($this->makePreference((int) $saved->id));

        $this->preferences->save($preference->changeTimezone(Timezone::createFromString('Asia/Vladivostok'), new TimezonePolicy));

        self::assertSame('Asia/Vladivostok', $this->preferences->findByUserId((int) $saved->id)?->timezone->getValue());
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

    private function restoreUser(int $id): User
    {
        return User::reconstitute(
            id: $id,
            version: 1,
            nickname: Nickname::createFromString(self::NICKNAME),
            email: Email::createFromString(self::EMAIL),
            password: PasswordHash::createFromHash(self::PASSWORD_HASH),
            avatarKey: null,
            embedding: null,
            status: UserStatus::VALID,
            isActive: true,
            role: UserRole::USER,
            lastActiveAt: null,
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
        return UserPreference::create(
            userId: $userId,
            timezone: Timezone::createFromString(Timezone::DEFAULT),
            theme: $theme,
            language: $language,
            interfaceType: $interfaceType,
            timezonePolicy: new TimezonePolicy,
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
            $table->string(UserModel::FIELD_NICKNAME, Nickname::MAX_LENGTH);
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
            $table->unsignedInteger(UserModel::FIELD_VERSION)->default(1);
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
