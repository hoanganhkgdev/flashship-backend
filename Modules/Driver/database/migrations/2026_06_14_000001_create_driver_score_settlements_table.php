<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_score_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['bonus', 'penalty']);
            $table->unsignedInteger('amount');          // VND (50000)
            $table->unsignedTinyInteger('score_at_settlement'); // điểm tại thời điểm chốt
            $table->date('week_start');
            $table->date('week_end');
            $table->enum('status', ['pending', 'processed'])->default('pending');
            $table->timestamps();

            $table->unique(['driver_id', 'week_start', 'type']); // 1 bonus + 1 penalty tối đa/tuần
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_score_settlements');
    }
};
