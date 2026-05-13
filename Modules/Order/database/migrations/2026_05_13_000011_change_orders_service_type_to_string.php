<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY service_type VARCHAR(50) NOT NULL DEFAULT 'delivery'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders MODIFY service_type ENUM('delivery','shopping','topup','bike','motor','car') NOT NULL DEFAULT 'delivery'");
    }
};
