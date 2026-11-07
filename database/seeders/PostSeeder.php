<?php

namespace Database\Seeders;

use App\Models\Post;
use Illuminate\Database\Seeder;

class PostSeeder extends Seeder
{
    /**
     * Create 10 fake posts records.
     */
    public function run(): void
    {
        Post::factory()->count(10)->create();
    }
}
