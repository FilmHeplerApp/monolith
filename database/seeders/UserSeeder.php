<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\Models\User;
use App\Infrastructure\Persistence\Eloquent\Models\UserPreference;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    private const int USER_COUNT = 10;


    public function run(): void
    {
        User::factory()
            ->count(self::USER_COUNT)
            ->has(UserPreference::factory(), 'preferences')
            ->create();
    }
}
