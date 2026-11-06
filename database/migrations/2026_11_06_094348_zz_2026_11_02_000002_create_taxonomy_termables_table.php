<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot polimorfico fra i termini e i modelli classificati (oc:8910). parent_id è una copia
        // denormalizzata della tassonomia del termine, per questo senza FK; gli indici non sono unique:
        // servono a leggere, non a vincolare. Il primo parte dal contenuto, il secondo dalla tassonomia
        // («tutti i contenuti di questo tassonomia», «tutti i sentieri di questo tassonomia»).
        // Niente timestamps, come gli altri pivot.
        if (! Schema::hasTable('taxonomy_termables')) {
            Schema::create('taxonomy_termables', function (Blueprint $table) {
                $table->id();
                $table->foreignId('taxonomy_term_id')->constrained('taxonomy_terms')->cascadeOnDelete();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->morphs('termable');
                $table->jsonb('properties')->nullable();

                // Lo stesso legame una volta sola: senza questo il vincolo vive nel codice, e
                // un job rilanciato o due salvataggi in concorrenza scriverebbero un doppione.
                // Rende anche idempotente l'insertOrIgnore con cui scriviamo i legami.
                $table->unique(['taxonomy_term_id', 'termable_type', 'termable_id'], 'taxonomy_termables_unique');

                $table->index(['termable_type', 'termable_id', 'parent_id'], 'taxonomy_termables_termable_parent_index');
                $table->index(['parent_id', 'termable_type'], 'taxonomy_termables_parent_termable_type_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_termables');
    }
};
