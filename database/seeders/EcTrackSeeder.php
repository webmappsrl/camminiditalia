<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Wm\WmPackage\Models\EcTrack;

class EcTrackSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        EcTrack::factory()->count(10)->create();
    }
}
