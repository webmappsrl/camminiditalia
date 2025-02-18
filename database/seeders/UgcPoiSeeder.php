<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Wm\WmPackage\Models\UgcPoi;

class UgcPoiSeeder extends Seeder
{
    public function run()
    {
        UgcPoi::factory()->count(10)->create();
    }
}
