<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_leave_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id')->index();
            $table->date('leave_date');
            $table->string('action', 12); // created | cancelled
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_leave_logs');
    }
};
