<?php

namespace Database\Factories;

use App\Domain\Shared\ValueObjects\Embedding;
use App\Domain\Users\Enums\User\UserRole;
use App\Domain\Users\Enums\User\UserStatus;
use App\Domain\Users\Services\BirthdayPolicy;
use App\Infrastructure\Persistence\Eloquent\Models\User;
use Database\Factories\Fixtures\EmbeddingFixture;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

#[UseModel(User::class)]
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            User::FIELD_NICKNAME => fake()->unique()->userName(),
            User::FIELD_EMAIL => fake()->unique()->safeEmail(),
            User::FIELD_PASSWORD => Hash::make('password'),
            User::FIELD_AVATAR_KEY => fake()->boolean(40)
                ? 'users/avatars/'.fake()->uuid().'/avatar.webp'
                : null,
            User::FIELD_EMBEDDING => Embedding::createFromArray(EmbeddingFixture::vector()),
            User::FIELD_STATUS => fake()->randomElement(UserStatus::cases()),
            User::FIELD_IS_ACTIVE => fake()->boolean(90),
            User::FIELD_ROLE => fake()->randomElement(UserRole::cases()),
            User::FIELD_LAST_ACTIVE_AT => fake()->optional(0.7)->dateTimeBetween('-30 days'),
            User::FIELD_BIRTHDAY => fake()->dateTimeBetween(
                sprintf('%d-01-01', BirthdayPolicy::MIN_YEAR),
                '-18 years',
            )->format('Y-m-d'),
            User::FIELD_SECRET_PHRASE_HASH => Hash::make('секретная фраза'),
        ];
    }
}
