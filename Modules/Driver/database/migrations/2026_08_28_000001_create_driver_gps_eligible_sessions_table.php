<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_gps_eligible_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('last_gps_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'started_at']);
            $table->index(['driver_id', 'ended_at']);
        });

        // Mốc khởi tạo tách riêng để rollout giữa ca không làm mất
        // phần giờ Online trước khi hệ thống bắt đầu theo dõi GPS.
        Schema::create('driver_gps_eligibility_states', function (Blueprint $table) {
            $table->foreignId('driver_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->timestamp('initialized_at');
            $table->timestamp('last_observed_gps_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_gps_eligibility_states');
        Schema::dropIfExists('driver_gps_eligible_sessions');
    }
};
