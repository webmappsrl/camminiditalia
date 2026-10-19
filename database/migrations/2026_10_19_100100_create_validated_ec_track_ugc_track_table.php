<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quali UGC track hanno prodotto una validazione (oc:8165).
 *
 * Serve a due cose: dire all'utente da quante uscite è nata una tappa sbloccata, e rifare i
 * conti quando una UGC track viene cancellata. Le righe cadono in cascata da entrambi i lati;
 * la validazione resta anche senza UGC track collegate, perché una volta ottenuta non si revoca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validated_ec_track_ugc_track', function (Blueprint $table) {
            $table->foreignId('validated_ec_track_id')->constrained('validated_ec_tracks')->cascadeOnDelete();
            $table->foreignId('ugc_track_id')->constrained('ugc_tracks')->cascadeOnDelete();

            $table->primary(['validated_ec_track_id', 'ugc_track_id']);
            $table->index('ugc_track_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validated_ec_track_ugc_track');
    }
};
