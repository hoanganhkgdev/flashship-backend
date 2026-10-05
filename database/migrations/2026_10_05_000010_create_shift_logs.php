<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_id')->index();
            $table->string('action', 20); // created | time_changed | activated | deactivated | deleted
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_logs');
    }
};
