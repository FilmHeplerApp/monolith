<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories\Users;

use App\Domain\Shared\ValueObjects\Embedding;
use App\Domain\Users\Entities\User;
use App\Domain\Users\Exceptions\InvalidUserValueException;
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
use Illuminate\Support\Facades\DB;

final class EloquentUserRepository implements UserRepository
{
    public function save(User $user): User
    {
        $attributes = $this->toAttributes($user);

        if ($user->id === null) {
            $model = UserModel::query()->create($attributes);

            return $this->toDomain($model);
        }

        $model = $this->findModel($user);
        $model->fill($attributes);
        $model->save();

        return $this->toDomain($model->refresh());
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
        $model = UserModel::query()
            ->where(UserModel::FIELD_EMAIL, $email->getValue())
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toDomain($model);
    }

    public function findByNickname(Nickname $nickname): ?User
    {
        $model = UserModel::query()
            ->whereRaw(
                'lower(' . UserModel::FIELD_NICKNAME . ') = lower(?)',
                [$nickname->getValue()],
            )
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toDomain($model);
    }

    public function delete(User $user): void
    {
        $this->findModel($user)->delete();
    }

    public function forceDelete(User $user): void
    {
        $model = $this->findModel($user, withTrashed: true);

        DB::transaction(function () use ($model): void {
            $this->purgeOwnedRows($model->id);
            $model->forceDelete();
        });
    }


    /**
     * Hard-delete rows that belong to the user.
     * Watch history, ratings, lists, tokens and recommendation cache attach here.
     */
    private function purgeOwnedRows(int $userId): void
    {
        UserPreferenceModel::query()
            ->where(UserPreferenceModel::FIELD_USER_ID, $userId)
            ->delete();
    }

    private function findModel(User $user, bool $withTrashed = false): UserModel
    {
        if ($user->id === null) {
            throw InvalidUserValueException::nonExistentUser();
        }

        $query = UserModel::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        $model = $query->find($user->id);

        if (!$model instanceof UserModel) {
            throw UserNotFoundException::byId($user->id);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(User $user): array
    {
        return [
            UserModel::FIELD_NICKNAME => $user->nickname->getValue(),
            UserModel::FIELD_EMAIL => $user->email->getValue(),
            UserModel::FIELD_PASSWORD => $user->password->getValue(),
            UserModel::FIELD_AVATAR_KEY => $user->avatarKey,
            UserModel::FIELD_EMBEDDING => $user->embedding?->getVector(),
            UserModel::FIELD_STATUS => $user->status,
            UserModel::FIELD_IS_ACTIVE => $user->isActive,
            UserModel::FIELD_ROLE => $user->role,
            UserModel::FIELD_LAST_ACTIVE_AT => $user->lastActiveAt,
            UserModel::FIELD_BIRTHDAY => $user->birthday->getValue(),
            UserModel::FIELD_SECRET_PHRASE_HASH => $user->secretPhraseHash->getValue(),
        ];
    }

    private function toDomain(UserModel $model): User
    {
        return new User(
            id: $model->id,
            nickname: Nickname::createFromString($model->nickname),
            email: Email::createFromString($model->email),
            password: PasswordHash::createFromHash($model->password),
            avatarKey: $model->avatar_key,
            embedding: Embedding::createFromArray($model->embedding),
            status: $model->status,
            isActive: $model->is_active,
            role: $model->role,
            lastActiveAt: $this->toImmutable($model->last_active_at),
            birthday: Birthday::createFromDateTime($this->requireImmutable($model->birthday)),
            secretPhraseHash: SecretPhraseHash::createFromHash($model->secret_phrase_hash),
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

    private function requireImmutable(DateTimeInterface $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($value);
    }
}
