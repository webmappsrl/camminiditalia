<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ugc_pois', function (Blueprint $table) {
            $table->foreign(['app_id'])->references(['id'])->on('apps')->onDelete('CASCADE');
        });

        Schema::table('ugc_tracks', function (Blueprint $table) {
            $table->foreign(['app_id'])->references(['id'])->on('apps')->onDelete('CASCADE');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->foreign(['app_id'])->references(['id'])->on('apps')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ugc_pois', function (Blueprint $table) {
            $table->dropForeign('ugc_pois_app_id_foreign');
        });

        Schema::table('ugc_tracks', function (Blueprint $table) {
            $table->dropForeign('ugc_tracks_app_id_foreign');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign('media_app_id_foreign');
        });
    }
};
