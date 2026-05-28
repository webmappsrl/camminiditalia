<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ugc_pois', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('properties');
        });

        DB::statement("CREATE INDEX ugc_pois_layer_id_idx ON ugc_pois ((properties->>'layer_id'))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ugc_pois_layer_id_idx');

        Schema::table('ugc_pois', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
