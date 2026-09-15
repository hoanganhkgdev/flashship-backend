<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_gps_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_location_at')->nullable();
            $table->string('diagnostic_status', 50)->nullable();
            $table->string('diagnostic_detail', 255)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('device_id', 100)->nullable();
            $table->json('diagnostic_payload')->nullable();
            $table->timestamp('warned_at')->nullable();
            $table->timestamp('offline_at');
            $table->timestamps();

            $table->index(['driver_id', 'offline_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_gps_incidents');
    }
};
