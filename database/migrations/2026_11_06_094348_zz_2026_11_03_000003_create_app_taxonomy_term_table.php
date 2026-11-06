<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quali tassonomie sono accese in quale app (oc:8910). Una riga vuol dire accesa:
        // non c'è uno stato "spenta" da scrivere, si toglie la riga. Ci vanno solo le
        // tassonomie (le righe senza parent_id): i termini seguono la loro.
        if (! Schema::hasTable('app_taxonomy_term')) {
            Schema::create('app_taxonomy_term', function (Blueprint $table) {
                $table->id();
                $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
                $table->foreignId('taxonomy_term_id')->constrained('taxonomy_terms')->cascadeOnDelete();
                // A quali contenuti si applica, in questa app: ["EcTrack"], ["EcPoi"] o
                // entrambi. Null vuol dire tutti. Sta sul pivot e non sulla tassonomia perché
                // la stessa tassonomia, condivisa fra app, può valere sui sentieri di una e
                // sui POI di un'altra.
                $table->jsonb('models')->nullable();

                $table->unique(['app_id', 'taxonomy_term_id'], 'app_taxonomy_term_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_taxonomy_term');
    }
};
