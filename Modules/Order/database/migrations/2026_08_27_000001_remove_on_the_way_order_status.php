<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('status', 'on_the_way')
            ->update(['status' => 'processing']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE orders MODIFY status ENUM('pending','assigned','processing','completed','cancelled') NOT NULL DEFAULT 'pending'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE orders MODIFY status ENUM('pending','assigned','processing','on_the_way','completed','cancelled') NOT NULL DEFAULT 'pending'"
            );
        }
    }
};
