<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('taxonomy_themes')) {
            return;
        }
        Schema::create('taxonomy_themes', function (Blueprint $table) {
            $table->id('id');

            $table->text('name');
            $table->text('description')->nullable();
            $table->string('excerpt')->nullable();
            $table->string('icon')->nullable();

            $table->text('identifier')->nullable()->unique();
            $table->timestamps();
            $table->jsonb('properties')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('taxonomy_themes');
    }
};
