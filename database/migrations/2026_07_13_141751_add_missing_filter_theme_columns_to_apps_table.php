<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonne previste dallo stub wm-package create_apps_table ma mai applicate
 * in questo ambiente (drift pre-esistente, scoperto da wm-package:publish-missing-migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            if (! Schema::hasColumn('apps', 'filter_theme')) {
                $table->boolean('filter_theme')->nullable();
            }
            if (! Schema::hasColumn('apps', 'filter_theme_label')) {
                $table->text('filter_theme_label')->nullable()->default('{"it":"Tema","en":"Theme"}');
            }
            if (! Schema::hasColumn('apps', 'filter_theme_exclude')) {
                $table->string('filter_theme_exclude')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['filter_theme', 'filter_theme_label', 'filter_theme_exclude']);
        });
    }
};
