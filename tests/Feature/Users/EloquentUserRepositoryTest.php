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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EloquentUserRepositoryTest extends TestCase
{
    private const string NICKNAME = 'anton';
    private const string EMAIL = 'anton@example.com';
    private const string PASSWORD_HASH = 'password-hash';
    private const string PHRASE_HASH = 'phrase-hash';
    private const string AVATAR_KEY = 'users/avatars/018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f13/avatar.webp';


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
        $saved = $this->users->save($this->makeUser(embedding: $this->embedding()));

        self::assertNotNull($saved->id);
        self::assertSame(self::NICKNAME, $saved->nickname->getValue());
        self::assertSame(self::EMAIL, $saved->email->getValue());
        self::assertSame(self::PASSWORD_HASH, $saved->password->getValue());
        self::assertSame(self::AVATAR_KEY, $saved->avatarKey);
        self::assertSame($this->embedding()->getVector(), $saved->embedding?->getVector());
        self::assertSame(UserStatus::VALID, $saved->status);
        self::assertTrue($saved->isActive);
        self::assertSame(UserRole::USER, $saved->role);
        self::assertSame('1994-03-12', $saved->birthday->getValue()->format('Y-m-d'));
        self::assertSame(self::PHRASE_HASH, $saved->secretPhraseHash->getValue());
        self::assertNull($saved->deletedAt);

        $byId = $this->users->findById($saved->id);
        $byEmail = $this->users->findByEmail(Email::createFromString(self::EMAIL));
        $byNickname = $this->users->findByNickname(Nickname::createFromString(self::NICKNAME));

        self::assertSame($saved->id, $byId?->id);
        self::assertSame($saved->id, $byEmail?->id);
        self::assertSame($saved->id, $byNickname?->id);
    }

    #[Test]
    public function it_finds_a_nickname_regardless_of_case_and_keeps_the_entered_form(): void
    {
        $saved = $this->users->save($this->makeUser(nickname: 'Anton'));

        $found = $this->users->findByNickname(Nickname::createFromString('aNtOn'));

        self::assertSame($saved->id, $found?->id);
        self::assertSame('Anton', $found?->nickname->getValue());
    }

    #[Test]
    public function it_rejects_a_nickname_that_differs_only_by_case(): void
    {
        $this->users->save($this->makeUser(nickname: 'Anton'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->users->save($this->makeUser(
            nickname: 'anton',
            email: 'other@example.com',
        ));
    }

    #[Test]
    public function it_updates_an_existing_user(): void
    {
        $saved = $this->users->save($this->makeUser());
        $renamed = $this->makeUser(
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

        $this->users->save($this->makeUser(id: 999));
    }

    #[Test]
    public function it_writes_and_reads_last_active_at(): void
    {
        $lastActiveAt = new DateTimeImmutable('2026-10-20 18:15:00');

        $saved = $this->users->save($this->makeUser(lastActiveAt: $lastActiveAt));
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
        $saved = $this->users->save($this->makeUser());
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
        $saved = $this->users->save($this->makeUser());
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
        $saved = $this->users->save($this->makeUser());
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

        $this->users->forceDelete($this->makeUser());
    }

    #[Test]
    public function it_rejects_delete_of_an_unsaved_user(): void
    {
        $this->expectException(InvalidUserValueException::class);

        $this->users->delete($this->makeUser());
    }

    #[Test]
    public function it_rejects_force_delete_of_an_unknown_user(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->users->forceDelete($this->makeUser(id: 999));
    }

    #[Test]
    public function it_saves_and_finds_preferences(): void
    {
        $saved = $this->users->save($this->makeUser());

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


    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->users = $this->app->make(UserRepository::class);
        $this->preferences = $this->app->make(UserPreferenceRepository::class);
    }


    private function makeUser(
        ?int               $id = null,
        string             $nickname = self::NICKNAME,
        string             $email = self::EMAIL,
        ?Embedding         $embedding = null,
        UserRole           $role = UserRole::USER,
        ?DateTimeImmutable $lastActiveAt = null,
    ): User {
        return new User(
            id: $id,
            nickname: Nickname::createFromString($nickname),
            email: Email::createFromString($email),
            password: PasswordHash::createFromHash(self::PASSWORD_HASH),
            avatarKey: self::AVATAR_KEY,
            embedding: $embedding,
            status: UserStatus::VALID,
            isActive: true,
            role: $role,
            lastActiveAt: $lastActiveAt,
            birthday: Birthday::createFromDateTime(new DateTimeImmutable('1994-03-12')),
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
            $table->date(UserModel::FIELD_BIRTHDAY);
            $table->string(UserModel::FIELD_SECRET_PHRASE_HASH);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(
                [new Expression('lower(' . UserModel::FIELD_NICKNAME . ')')],
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
