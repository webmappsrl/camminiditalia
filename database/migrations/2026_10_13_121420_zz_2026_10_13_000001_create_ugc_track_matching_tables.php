<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quali segmenti di quale EcTrack corrispondono a ogni UgcTrack (oc:8773). matched_segments
        // è l'elenco ordinato degli indici corrispondenti, numerati da 0 dall'inizio della EcTrack,
        // unici anche quando la EcTrack è fatta di più pezzi. I parametri usati stanno sulla riga:
        // righe calcolate con parametri diversi non si possono unire.
        if (! Schema::hasTable('ugc_track_ec_track_matches')) {
            Schema::create('ugc_track_ec_track_matches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ugc_track_id')->constrained('ugc_tracks')->cascadeOnDelete();
                $table->foreignId('ec_track_id')->constrained('ec_tracks')->cascadeOnDelete();
                $table->integer('app_id')->nullable();
                $table->jsonb('matched_segments');
                $table->integer('total_segments');
                $table->integer('segment_length_meters');
                $table->integer('max_distance_meters');
                $table->timestamps();

                $table->unique(['ugc_track_id', 'ec_track_id']);
                $table->index('ec_track_id');
            });
        }

        // Stato del calcolo per UgcTrack, anche quando non corrisponde a nessuna EcTrack:
        // distingue "nessuna EcTrack corrispondente" da "calcolo mai riuscito".
        if (! Schema::hasTable('ugc_track_matching_runs')) {
            Schema::create('ugc_track_matching_runs', function (Blueprint $table) {
                $table->foreignId('ugc_track_id')->primary()->constrained('ugc_tracks')->cascadeOnDelete();
                $table->string('status');
                $table->integer('segment_length_meters')->nullable();
                $table->integer('max_distance_meters')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('computed_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_track_ec_track_matches');
        Schema::dropIfExists('ugc_track_matching_runs');
    }
};
