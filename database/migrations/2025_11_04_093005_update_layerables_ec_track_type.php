<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('layerables')
            ->where('layerable_type', 'App\Models\EcTrack')
            ->update(['layerable_type' => 'Wm\WmPackage\Models\EcTrack']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('layerables')
            ->where('layerable_type', 'Wm\WmPackage\Models\EcTrack')
            ->update(['layerable_type' => 'App\Models\EcTrack']);
    }
};
