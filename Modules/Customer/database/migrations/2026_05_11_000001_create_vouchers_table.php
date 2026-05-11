<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->enum('type', ['percent', 'fixed']);
            $table->unsignedInteger('value');                          // % or VND
            $table->string('description')->nullable();
            $table->unsignedInteger('min_order_value')->nullable();    // minimum order to apply
            $table->unsignedInteger('max_discount')->nullable();       // cap for percent type
            $table->json('service_types')->nullable();                 // null = all services
            $table->unsignedBigInteger('city_id')->nullable();        // null = all cities
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();        // null = unlimited
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            $table->index(['is_active', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
