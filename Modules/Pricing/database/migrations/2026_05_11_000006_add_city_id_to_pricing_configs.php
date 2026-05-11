<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->after('service_type');
            $table->foreign('city_id')->references('id')->on('cities')->cascadeOnDelete();

            $table->dropUnique(['service_type']);
            $table->unique(['service_type', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            $table->dropUnique(['service_type', 'city_id']);
            $table->dropForeign(['city_id']);
            $table->dropColumn('city_id');
            $table->unique('service_type');
        });
    }
};
