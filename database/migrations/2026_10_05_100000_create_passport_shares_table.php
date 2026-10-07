<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Condivisioni del passaporto: una riga per utente e cosa condivisa, una tappa
 * (oc:8702) o un cammino completato (oc:8703), nella stessa tabella con una
 * relazione polimorfica (`shareable`: EcTrack o Layer). Nata in oc:8702 come
 * `2026_10_05_100000_create_passport_stage_shares_table` (tabella
 * `passport_stage_shares`) e rinominata in oc:8703 prima del primo rilascio:
 * chi aveva lanciato quella versione deve farne il rollback prima di
 * aggiornare il codice, poi `migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passport_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('layer_id')->constrained('layers')->cascadeOnDelete();
            $table->morphs('shareable');
            $table->jsonb('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'shareable_type', 'shareable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passport_shares');
    }
};
