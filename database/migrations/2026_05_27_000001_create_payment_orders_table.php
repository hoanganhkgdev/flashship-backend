<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id');
            $table->enum('type', ['topup', 'debt_payment']);
            $table->unsignedBigInteger('ref_id')->nullable()->comment('debt_id nếu type=debt_payment');
            $table->bigInteger('order_code')->unique()->comment('PayOS order code');
            $table->unsignedInteger('amount');
            $table->enum('status', ['pending', 'paid', 'cancelled', 'expired'])->default('pending');
            $table->string('payment_link_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->text('qr_code')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'status']);
            $table->index('order_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
