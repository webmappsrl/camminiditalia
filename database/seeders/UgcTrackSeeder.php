<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Wm\WmPackage\Models\UgcTrack;

class UgcTrackSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        UgcTrack::factory()->count(10)->create();
    }
}
