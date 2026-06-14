<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->string('place_name', 100)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropColumn('place_name');
        });
    }
};
