<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('layer_id')->constrained('layers')->cascadeOnDelete();
            $table->foreignId('app_id')->nullable()->constrained('apps')->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->text('serial_number')->nullable();
            $table->timestamp('disclaimer_accepted_at');
            $table->timestamps();
        });

        DB::statement("CREATE UNIQUE INDEX certification_requests_one_pending_per_user_layer ON certification_requests (user_id, layer_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_requests');
    }
};
