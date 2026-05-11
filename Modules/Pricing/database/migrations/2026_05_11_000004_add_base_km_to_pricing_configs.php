<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            $table->unsignedSmallInteger('base_km')->default(0)->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            $table->dropColumn('base_km');
        });
    }
};