<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('driver_score_logs')) return;

        Schema::create('driver_score_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id');
            $table->tinyInteger('delta');
            $table->unsignedSmallInteger('score_before');
            $table->unsignedSmallInteger('score_after');
            $table->string('reason', 50);
            $table->timestamps();

            $table->foreign('driver_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['driver_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_score_logs');
    }
};
