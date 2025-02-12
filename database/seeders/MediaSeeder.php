<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Wm\WmPackage\Models\Media;

class MediaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Media::factory()->count(10)->create();
    }
}
