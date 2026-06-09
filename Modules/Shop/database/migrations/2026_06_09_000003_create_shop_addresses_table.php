<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_user_id');
            $table->string('label', 100)->nullable();   // tên gợi nhớ: "Khách A", "Chi nhánh Q1"
            $table->string('name', 100);                // tên người nhận
            $table->string('phone', 20);                // SĐT người nhận
            $table->string('address');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();

            $table->foreign('shop_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('shop_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_addresses');
    }
};
