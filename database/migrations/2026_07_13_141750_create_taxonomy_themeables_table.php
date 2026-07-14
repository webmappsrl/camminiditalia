<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('taxonomy_themeables')) {
            return;
        }
        Schema::create('taxonomy_themeables', function (Blueprint $table) {
            $table->id();
            $table->integer('taxonomy_theme_id');
            $table->morphs('taxonomy_themeable');
        });
    }

    public function down()
    {
        Schema::dropIfExists('taxonomy_themeables');
    }
};
