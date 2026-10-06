<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passport_stage_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('layer_id')->constrained('layers')->cascadeOnDelete();
            $table->foreignId('ec_track_id')->constrained('ec_tracks')->cascadeOnDelete();
            $table->jsonb('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ec_track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passport_stage_shares');
    }
};
