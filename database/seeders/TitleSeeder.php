<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\Models\Title;
use Illuminate\Database\Seeder;

class TitleSeeder extends Seeder
{
    public function run(): void
    {
        Title::factory()->count(100)->create();
    }
}
