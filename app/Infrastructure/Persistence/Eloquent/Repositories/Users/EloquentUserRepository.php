<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories\Users;

use App\Domain\Users\Entities\User;
use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\Exceptions\UserAlreadyExistsException;
use App\Domain\Users\Exceptions\UserConcurrentModificationException;
use App\Domain\Users\Exceptions\UserNotFoundException;
use App\Domain\Users\Repositories\UserRepository;
use App\Domain\Users\ValueObjects\User\Birthday;
use App\Domain\Users\ValueObjects\User\Email;
use App\Domain\Users\ValueObjects\User\Nickname;
use App\Domain\Users\ValueObjects\User\PasswordHash;
use App\Domain\Users\ValueObjects\User\SecretPhraseHash;
use App\Infrastructure\Persistence\Eloquent\Models\User as UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserPreference as UserPreferenceModel;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EloquentUserRepository implements UserRepository
{
    private const int INITIAL_VERSION = 1;


    public function save(User $user): User
    {
        try {
            return $user->id === null ? $this->insert($user) : $this->update($user->id, $user);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->translateUniqueViolation($exception);
        }
    }

    public function touchLastActiveAt(int $id, DateTimeImmutable $lastActiveAt): void
    {
        $updated = UserModel::query()
            ->whereKey($id)
            ->toBase()
            ->update([UserModel::FIELD_LAST_ACTIVE_AT => $lastActiveAt]);

        if ($updated === 0) {
            throw UserNotFoundException::byId($id);
        }
    }

    public function findById(int $id): ?User
    {
        $model = UserModel::query()->find($id);

        if ($model === null) {
            return null;
        }

        return $this->toDomain($model);
    }

    public function findByEmail(Email $email): ?User
    {
        $model = $this->whereEmail(UserModel::query(), $email)->first();

        if ($model === null) {
            return null;
        }

        return $this->toDomain($model);
    }

    public function findByNickname(Nickname $nickname): ?User
    {
        $model = $this->whereNickname(UserModel::query(), $nickname)->first();

        if ($model === null) {
            return null;
        }

        return $this->toDomain($model);
    }

    public function existsByEmail(Email $email): bool
    {
        return $this->whereEmail(UserModel::query()->withTrashed(), $email)->exists();
    }

    public function existsByNickname(Nickname $nickname): bool
    {
        return $this->whereNickname(UserModel::query()->withTrashed(), $nickname)->exists();
    }

    public function delete(User $user): void
    {
        $this->findModel($this->persistedId($user))->delete();
    }

    public function forceDelete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $model = $this->findModel($this->persistedId($user), withTrashed: true, lock: true);
            $this->purgeOwnedRows($model);
            if (!$model->forceDelete()) {
                throw new RuntimeException('User deletion was cancelled; related data cleanup has been rolled back.');
            }
        });
    }


    private function insert(User $user): User
    {
        $model = new UserModel;
        $model->forceFill([
            ...$this->toAttributes($user),
            UserModel::FIELD_VERSION => self::INITIAL_VERSION,
        ]);
        $model->save();

        return $this->toDomain($model);
    }

    private function update(int $id, User $user): User
    {
        $attributes = (new UserModel)->forceFill($this->toAttributes($user))->getAttributes();

        $updated = UserModel::query()
            ->whereKey($id)
            ->where(UserModel::FIELD_VERSION, $user->version)
            ->update([
                ...$attributes,
                UserModel::FIELD_VERSION => (int) $user->version + 1,
            ]);

        if ($updated === 0) {
            throw UserModel::query()->whereKey($id)->exists()
                ? UserConcurrentModificationException::byId($id)
                : UserNotFoundException::byId($id);
        }

        return $this->toDomain($this->findModel($id));
    }

    private function translateUniqueViolation(UniqueConstraintViolationException $exception): RuntimeException
    {
        if (in_array(UserModel::FIELD_EMAIL, $exception->columns, true)) {
            return UserAlreadyExistsException::emailTaken();
        }

        if (str_contains($exception->getMessage(), UserModel::NICKNAME_LOWER_UNIQUE_INDEX)) {
            return UserAlreadyExistsException::nicknameTaken();
        }

        return $exception;
    }

    private function whereEmail(Builder $query, Email $email): Builder
    {
        return $query->where(UserModel::FIELD_EMAIL, $email->getValue());
    }

    private function whereNickname(Builder $query, Nickname $nickname): Builder
    {
        return $query->whereRaw(
            'lower('.UserModel::FIELD_NICKNAME.') = lower(?)',
            [$nickname->getValue()],
        );
    }

    private function purgeOwnedRows(UserModel $model): void
    {
        UserPreferenceModel::query()
            ->where(UserPreferenceModel::FIELD_USER_ID, $model->id)
            ->delete();

        DB::table('sessions')->where('user_id', $model->id)->delete();
        DB::table('password_reset_tokens')
            ->whereRaw('lower(email) = lower(?)', [$model->email])
            ->delete();
    }

    private function persistedId(User $user): int
    {
        if ($user->id === null) {
            throw InvalidUserValueException::nonExistentUser();
        }

        return $user->id;
    }

    private function findModel(
        int  $id,
        bool $withTrashed = false,
        bool $lock = false,
    ): UserModel {
        $query = UserModel::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $model = $query->find($id);

        if (!$model instanceof UserModel) {
            throw UserNotFoundException::byId($id);
        }

        return $model;
    }

    private function toAttributes(User $user): array
    {
        return [
            UserModel::FIELD_NICKNAME => $user->nickname->getValue(),
            UserModel::FIELD_EMAIL => $user->email->getValue(),
            UserModel::FIELD_PASSWORD => $user->password->getValue(),
            UserModel::FIELD_AVATAR_KEY => $user->avatarKey,
            UserModel::FIELD_EMBEDDING => $user->embedding,
            UserModel::FIELD_STATUS => $user->status,
            UserModel::FIELD_IS_ACTIVE => $user->isActive,
            UserModel::FIELD_ROLE => $user->role,
            UserModel::FIELD_BIRTHDAY => $user->birthday?->getValue(),
            UserModel::FIELD_SECRET_PHRASE_HASH => $user->secretPhraseHash?->getValue(),
        ];
    }

    private function toDomain(UserModel $model): User
    {
        return User::reconstitute(
            id: $model->id,
            version: $model->version,
            nickname: Nickname::createFromString($model->nickname),
            email: Email::createFromString($model->email),
            password: PasswordHash::createFromHash($model->password),
            avatarKey: $model->avatar_key,
            embedding: $model->embedding,
            status: $model->status,
            isActive: $model->is_active,
            role: $model->role,
            lastActiveAt: $this->toImmutable($model->last_active_at),
            birthday: $model->birthday === null ? null : Birthday::createFromDateTime(DateTimeImmutable::createFromInterface($model->birthday)),
            secretPhraseHash: $model->secret_phrase_hash === null ? null : SecretPhraseHash::createFromHash($model->secret_phrase_hash),
            createdAt: $this->toImmutable($model->created_at),
            updatedAt: $this->toImmutable($model->updated_at),
            deletedAt: $this->toImmutable($model->deleted_at),
        );
    }

    private function toImmutable(?DateTimeInterface $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value);
    }
}
