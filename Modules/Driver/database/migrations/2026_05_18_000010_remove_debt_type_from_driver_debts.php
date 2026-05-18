<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_debts', function (Blueprint $table) {
            $table->dropColumn('debt_type');
        });
    }

    public function down(): void
    {
        Schema::table('driver_debts', function (Blueprint $table) {
            $table->string('debt_type')->default('weekly')->after('driver_id');
        });
    }
};
