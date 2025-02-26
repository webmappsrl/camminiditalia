<?php

namespace Database\Seeders;

use Wm\WmPackage\Models\App;
use Illuminate\Database\Seeder;

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
