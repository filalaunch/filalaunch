<?php

namespace Database\Seeders;

use App\Models\News;
use Illuminate\Database\Seeder;

class NewsSeeder extends Seeder
{
    /**
     * Create 10 fake news records.
     */
    public function run(): void
    {
        News::factory()->count(10)->create();
    }
}
