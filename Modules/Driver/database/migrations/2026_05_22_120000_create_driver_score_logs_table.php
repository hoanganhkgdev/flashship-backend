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
            $table->integer('delta');
            $table->integer('score_before');
            $table->integer('score_after');
            $table->string('reason', 50);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['driver_id', 'created_at']);
            $table->foreign('driver_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_score_logs');
    }
};
