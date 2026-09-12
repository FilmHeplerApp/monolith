<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use Illuminate\Database\Seeder;

class TitleSeeder extends Seeder
{
    public function run(): void
    {
        Title::factory()->count(100)->create();
    }
}
