<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'order.max_active_per_driver')
            ->where('value', '2')
            ->update(['value' => '3', 'updated_at' => now()]);

        DB::table('city_operational_settings')
            ->where('key', 'order.max_active_per_driver')
            ->where('value', '2')
            ->update(['value' => '3', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'order.max_active_per_driver')
            ->where('value', '3')
            ->update(['value' => '2', 'updated_at' => now()]);

        DB::table('city_operational_settings')
            ->where('key', 'order.max_active_per_driver')
            ->where('value', '3')
            ->update(['value' => '2', 'updated_at' => now()]);
    }
};
