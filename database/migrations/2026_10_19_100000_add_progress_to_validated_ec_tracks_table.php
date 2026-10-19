<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avanzamento parziale di una tappa (oc:8165).
 *
 * Fino a qui `validated_ec_tracks` conteneva solo validazioni, tutte concesse a mano da un
 * gestore. Con il calcolo automatico la stessa riga ospita anche una tappa in corso: `progress`
 * è la quota di segmenti percorsi, e `validated_at` resta nullo finché non supera la soglia.
 * Una riga non validata non è una validazione: chi legge la tabella filtra con
 * `PassportStage::validated()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validated_ec_tracks', function (Blueprint $table) {
            $table->smallInteger('progress')->default(0);
            $table->timestamp('validated_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Le righe non validate non hanno posto in uno schema con validated_at NOT NULL.
        \DB::table('validated_ec_tracks')->whereNull('validated_at')->delete();

        Schema::table('validated_ec_tracks', function (Blueprint $table) {
            $table->dropColumn('progress');
            $table->timestamp('validated_at')->nullable(false)->change();
        });
    }
};
