<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Wm\WmPackage\Models\App;

class AppSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        App::factory()->count(10)->create();
    }
}
