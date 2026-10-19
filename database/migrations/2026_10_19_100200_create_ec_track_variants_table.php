<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Varianti di una tappa (oc:8165).
 *
 * Una tappa può essere dichiarata variante di un'altra: le due si equivalgono, basta averne
 * percorsa una, e nel totale del cammino contano per una sola. Senza questa relazione un
 * cammino con rami alternativi non raggiunge mai il 100%, perché le varianti stanno nello
 * stesso layer della principale e ne gonfiano il totale.
 *
 * Tabella a sé e non una colonna su `ec_tracks`: quella tabella è di `wm-package`, e una
 * colonna aggiunta da una migration del consumer fa divergere lo schema che il package
 * pubblica. La chiave primaria su `ec_track_id` impone da sola «una tappa ha al più una
 * principale»; il divieto di catene e di cicli sta in `EcTrackVariant`, perché in DDL non si
 * esprime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_track_variants', function (Blueprint $table) {
            $table->foreignId('ec_track_id')->primary()->constrained('ec_tracks')->cascadeOnDelete();
            $table->foreignId('main_ec_track_id')->constrained('ec_tracks')->cascadeOnDelete();
            $table->timestamps();

            $table->index('main_ec_track_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_track_variants');
    }
};
