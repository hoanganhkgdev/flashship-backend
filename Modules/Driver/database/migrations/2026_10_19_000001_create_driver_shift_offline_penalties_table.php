<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_shift_offline_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            // Ngày bắt đầu của ca (ca qua đêm tính theo ngày bắt đầu).
            $table->date('shift_date');
            $table->unsignedInteger('offline_seconds');
            $table->unsignedInteger('amount');
            $table->foreignId('debt_id')->nullable()->constrained('driver_debts')->nullOnDelete();
            $table->timestamps();

            // Mỗi tài xế chỉ bị phạt một lần cho mỗi ca mỗi ngày.
            $table->unique(['driver_id', 'shift_id', 'shift_date'], 'shift_offline_penalty_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_shift_offline_penalties');
    }
};
