<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('driver_location_logs')) return;
        Schema::create('driver_location_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('bearing')->nullable();
            $table->string('source', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['driver_id', 'created_at']);
            $table->foreign('driver_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_location_logs');
    }
};
