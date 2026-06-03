<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_pricing_configs', function (Blueprint $table) {
            $table->id();
            $table->enum('cargo_type', ['food', 'flowers', 'parcel']);
            $table->unsignedBigInteger('city_id')->nullable();
            $table->json('slabs')->comment('Array of {max_km, fee}');
            $table->integer('over_max_per_km')->default(3000)->comment('Phí mỗi km vượt max_km');
            $table->integer('weight_per_kg')->default(0)->comment('Phụ phí mỗi kg (chỉ parcel)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['cargo_type', 'city_id']);
            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_pricing_configs');
    }
};
