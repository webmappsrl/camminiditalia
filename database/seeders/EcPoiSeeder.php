<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Wm\WmPackage\Models\EcPoi;

class EcPoiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        EcPoi::factory()->count(10)->create();
    }
}
