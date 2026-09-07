<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('taxonomy_wheres')) {
            return;
        }

        if (! Schema::hasColumn('taxonomy_wheres', 'identifier')) {
            Schema::table('taxonomy_wheres', function (Blueprint $table) {
                $table->text('identifier')->nullable();
            });
        }

        // Backfill in SQL puro: mai via Eloquent, per non far transitare la
        // geometria PostGIS attraverso l'ORM (nel package viene scritta solo
        // con DB::statement + ST_GeomFromGeoJSON).
        //
        // Regola (vedi docs/features/8469-fix-identifier-taxonomy-where):
        //   osmfeatures_id presente -> "{source}-{osmfeatures_id}"
        //   osm2cai_id presente     -> "{source}-{osm2cai_id}"
        //   altrimenti              -> "{source}-{slug(name)}"
        DB::statement(<<<'SQL'
            UPDATE taxonomy_wheres
            SET identifier = NULLIF(
                btrim(
                    regexp_replace(
                        lower(
                            COALESCE(properties->>'source', '')
                            || '-'
                            || COALESCE(
                                properties->>'osmfeatures_id',
                                properties->>'osm2cai_id',
                                CASE
                                    WHEN name IS NULL OR btrim(name) = '' THEN ''
                                    WHEN left(btrim(name), 1) = '{' THEN COALESCE(
                                        name::jsonb->>'it',
                                        name::jsonb->>'en',
                                        ''
                                    )
                                    ELSE name
                                END
                            )
                        ),
                        '[^a-z0-9]+', '-', 'g'
                    ),
                    '-'
                ),
                ''
            )
            WHERE identifier IS NULL
        SQL);

        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS taxonomy_wheres_identifier_unique
            ON taxonomy_wheres (identifier)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS taxonomy_wheres_identifier_unique');

        if (Schema::hasColumn('taxonomy_wheres', 'identifier')) {
            Schema::table('taxonomy_wheres', function (Blueprint $table) {
                $table->dropColumn('identifier');
            });
        }
    }
};
