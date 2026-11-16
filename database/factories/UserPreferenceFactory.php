<?php

namespace Database\Factories;

use App\Domain\Users\Enums\UserPreference\InterfaceType;
use App\Domain\Users\Enums\UserPreference\Language;
use App\Domain\Users\Enums\UserPreference\Theme;
use App\Domain\Users\ValueObjects\UserPreference\Timezone;
use App\Infrastructure\Persistence\Eloquent\Models\User;
use App\Infrastructure\Persistence\Eloquent\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

#[UseModel(UserPreference::class)]
class UserPreferenceFactory extends Factory
{
    private const array TIMEZONES = [
        Timezone::DEFAULT,
        'UTC',
        'Europe/Kaliningrad',
        'Asia/Vladivostok',
    ];


    public function definition(): array
    {
        return [
            UserPreference::FIELD_USER_ID => User::factory(),
            UserPreference::FIELD_TIMEZONE => fake()->randomElement(self::TIMEZONES),
            UserPreference::FIELD_THEME => fake()->randomElement(Theme::cases()),
            UserPreference::FIELD_LANGUAGE => fake()->randomElement(Language::cases()),
            UserPreference::FIELD_INTERFACE_TYPE => fake()->randomElement(InterfaceType::cases()),
        ];
    }
}
