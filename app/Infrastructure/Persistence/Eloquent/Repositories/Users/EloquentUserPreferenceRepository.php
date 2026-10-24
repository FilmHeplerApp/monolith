<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories\Users;

use App\Domain\Users\Entities\UserPreference;
use App\Domain\Users\Exceptions\UserNotFoundException;
use App\Domain\Users\Repositories\UserPreferenceRepository;
use App\Domain\Users\ValueObjects\UserPreference\Timezone;
use App\Infrastructure\Persistence\Eloquent\Models\User as UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserPreference as UserPreferenceModel;
use DateTimeImmutable;
use DateTimeInterface;

final class EloquentUserPreferenceRepository implements UserPreferenceRepository
{
    public function save(UserPreference $preference): UserPreference
    {
        $this->assertUserExists($preference->userId);

        $model = UserPreferenceModel::query()->updateOrCreate(
            [UserPreferenceModel::FIELD_USER_ID => $preference->userId],
            [
                UserPreferenceModel::FIELD_TIMEZONE => $preference->timezone->getValue(),
                UserPreferenceModel::FIELD_THEME => $preference->theme,
                UserPreferenceModel::FIELD_LANGUAGE => $preference->language,
                UserPreferenceModel::FIELD_INTERFACE_TYPE => $preference->interfaceType,
            ],
        );

        return $this->toDomain($model);
    }

    public function findByUserId(int $userId): ?UserPreference
    {
        $model = UserPreferenceModel::query()
            ->where(UserPreferenceModel::FIELD_USER_ID, $userId)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toDomain($model);
    }


    private function assertUserExists(int $userId): void
    {
        $exists = UserModel::query()->whereKey($userId)->exists();

        if (!$exists) {
            throw UserNotFoundException::byId($userId);
        }
    }

    private function toDomain(UserPreferenceModel $model): UserPreference
    {
        return new UserPreference(
            id: $model->id,
            userId: $model->user_id,
            timezone: Timezone::createFromString($model->timezone),
            theme: $model->theme,
            language: $model->language,
            interfaceType: $model->interface_type,
            createdAt: $this->toImmutable($model->created_at),
            updatedAt: $this->toImmutable($model->updated_at),
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
