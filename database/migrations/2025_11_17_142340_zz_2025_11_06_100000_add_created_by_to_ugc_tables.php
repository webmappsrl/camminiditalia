<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ugc_pois', function (Blueprint $table) {
            $table->string('created_by', 20)->nullable();
        });

        Schema::table('ugc_tracks', function (Blueprint $table) {
            $table->string('created_by', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ugc_pois', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });

        Schema::table('ugc_tracks', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
    }
};

