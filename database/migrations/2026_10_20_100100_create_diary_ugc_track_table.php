<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_ugc_track', function (Blueprint $table) {
            $table->foreignId('diary_id')->constrained('diaries')->cascadeOnDelete();
            $table->foreignId('ugc_track_id')->constrained('ugc_tracks')->cascadeOnDelete();

            $table->primary(['diary_id', 'ugc_track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_ugc_track');
    }
};
