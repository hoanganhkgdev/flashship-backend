<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zalo_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('access_token', 1024);
            $table->string('refresh_token', 1024);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zalo_tokens');
    }
};
