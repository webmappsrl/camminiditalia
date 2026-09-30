<?php

use App\Models\ValidatedEcTrack;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validated_ec_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ec_track_id')->constrained('ec_tracks')->cascadeOnDelete();
            $table->foreignId('layer_id')->constrained('layers')->cascadeOnDelete();
            $table->foreignId('certification_request_id')->nullable()->index()->constrained('certification_requests')->nullOnDelete();
            $table->string('source')->default(ValidatedEcTrack::SOURCE_MANUAL);
            $table->timestamp('validated_at');
            $table->timestamps();

            $table->unique(['user_id', 'ec_track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validated_ec_tracks');
    }
};
