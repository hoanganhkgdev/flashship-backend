<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->nullable();
            $table->enum('service_type', ['delivery', 'shopping', 'topup', 'bike', 'motor', 'car'])->default('delivery');
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('dispatching_to_driver_id')->nullable();
            $table->unsignedTinyInteger('dispatch_attempts')->default(0);
            $table->unsignedBigInteger('sender_platform_id')->nullable();
            $table->string('platform')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->enum('status', ['pending', 'assigned', 'processing', 'completed', 'cancelled'])->default('pending');

            // Điểm lấy
            $table->string('pickup_address')->nullable();
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();
            $table->string('pickup_phone', 20)->nullable();
            $table->string('sender_name')->nullable();
            $table->string('store_name')->nullable();

            // Điểm giao
            $table->string('delivery_address')->nullable();
            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();
            $table->string('delivery_phone', 20)->nullable();
            $table->string('receiver_name')->nullable();

            // Phí & thanh toán
            $table->unsignedInteger('shipping_fee')->default(0);
            $table->unsignedInteger('bonus_fee')->default(0);
            $table->boolean('is_freeship')->default(false);
            $table->decimal('distance', 8, 2)->nullable();
            $table->string('payment_method')->default('cod');
            $table->unsignedInteger('cod_amount')->nullable();

            // Nội dung đơn
            $table->text('order_note')->nullable();

            // Đánh giá
            $table->unsignedTinyInteger('driver_rating')->nullable();
            $table->text('driver_rating_note')->nullable();

            // Timestamps đặc biệt
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            $table->foreign('delivery_man_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('dispatching_to_driver_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('sender_platform_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('created_at');
            $table->index(['status', 'city_id']);
            $table->index(['status', 'delivery_man_id']);
        });

        Schema::create('order_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type');
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('order_dispatch_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('driver_id');
            $table->timestamp('offered_at');
            $table->timestamp('responded_at')->nullable();
            $table->enum('result', ['pending', 'accepted', 'declined', 'expired'])->default('pending');
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('driver_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['order_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_dispatch_logs');
        Schema::dropIfExists('order_histories');
        Schema::dropIfExists('orders');
    }
};
