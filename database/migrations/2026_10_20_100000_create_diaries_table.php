<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diaries', function (Blueprint $table) {
            $table->id();
            // Generato dall'app: rende idempotente la creazione lato client.
            $table->string('uuid');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('layer_id')->constrained('layers')->cascadeOnDelete();
            $table->foreignId('ec_track_id')->nullable()->constrained('ec_tracks')->nullOnDelete();
            $table->text('description')->nullable();
            // Lettura pubblica futura (oc:8169): sempre false in questo ciclo.
            $table->boolean('is_public')->default(false);
            // Consenso alla ripubblicazione sui canali di Cammini d'Italia (oc:8169, ciclo 2):
            // distinto da is_public, che riguarda la lettura da parte degli altri utenti.
            $table->boolean('publication_consent')->default(false);
            // Valutazioni facoltative da 1 a 5 (oc:8169, ciclo 2): sulla tappa «Cammino» vale «Tappa».
            $table->unsignedTinyInteger('rating_route')->nullable();
            $table->unsignedTinyInteger('rating_hospitality')->nullable();
            $table->unsignedTinyInteger('rating_difficulty')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'uuid']);
            $table->index(['user_id', 'layer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diaries');
    }
};
