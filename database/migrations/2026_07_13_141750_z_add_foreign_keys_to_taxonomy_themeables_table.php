<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $existing = DB::select("SELECT constraint_name FROM information_schema.table_constraints WHERE table_name = 'taxonomy_themeables' AND constraint_type = 'FOREIGN KEY'");
        if (! empty($existing)) {
            return;
        }
        Schema::table('taxonomy_themeables', function (Blueprint $table) {
            $table->foreign(['taxonomy_theme_id'])->references(['id'])->on('taxonomy_themes');
        });
    }

    public function down()
    {
        Schema::table('taxonomy_themeables', function (Blueprint $table) {
            $table->dropForeign('taxonomy_themeables_taxonomy_theme_id_foreign');
        });
    }
};
