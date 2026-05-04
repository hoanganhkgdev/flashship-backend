<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_configs', function (Blueprint $table) {
            $table->id();
            $table->string('service_type')->unique();
            $table->string('label');
            $table->unsignedInteger('base_fee')->default(0);
            $table->unsignedInteger('per_km_fee')->default(0);
            $table->unsignedInteger('min_fee')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_configs');
    }
};
