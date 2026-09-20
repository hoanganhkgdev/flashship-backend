<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('vouchers')
            ->where('first_order_only', true)
            ->whereNull('max_distance_km')
            ->update(['max_distance_km' => 10]);
    }

    public function down(): void
    {
        // Data-only safety rule: do not guess which pre-existing vouchers had
        // an intentional distance limit before this migration.
    }
};
