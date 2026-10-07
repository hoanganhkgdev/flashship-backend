<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_market_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->enum('status', ['open', 'claimed', 'expired', 'cancelled'])->default('open');
            $table->string('open_reason', 30);
            $table->unsignedSmallInteger('offer_attempts')->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['city_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_market_listings');
    }
};
