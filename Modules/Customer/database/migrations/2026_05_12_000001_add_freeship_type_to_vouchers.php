<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE vouchers MODIFY COLUMN type ENUM('percent', 'fixed', 'freeship') NOT NULL");
        }
        // SQLite has no ENUM enforcement — 'freeship' works without schema change
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE vouchers MODIFY COLUMN type ENUM('percent', 'fixed') NOT NULL");
        }
    }
};
