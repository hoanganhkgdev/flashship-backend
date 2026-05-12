<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE vouchers MODIFY COLUMN type ENUM('percent', 'fixed', 'freeship') NOT NULL");
            return;
        }

        // SQLite: recreate table with updated CHECK constraint
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement("
            CREATE TABLE vouchers_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code VARCHAR(32) NOT NULL,
                type TEXT NOT NULL CHECK(type IN ('percent', 'fixed', 'freeship')),
                value INTEGER NOT NULL,
                description VARCHAR(255),
                min_order_value INTEGER,
                max_discount INTEGER,
                service_types TEXT,
                city_id INTEGER,
                expires_at DATETIME,
                usage_limit INTEGER,
                used_count INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME,
                updated_at DATETIME
            )
        ");
        DB::statement('INSERT INTO vouchers_new SELECT * FROM vouchers');
        DB::statement('DROP TABLE vouchers');
        DB::statement('ALTER TABLE vouchers_new RENAME TO vouchers');
        DB::statement('CREATE UNIQUE INDEX vouchers_code_unique ON vouchers (code)');
        DB::statement('CREATE INDEX vouchers_is_active_expires_at_index ON vouchers (is_active, expires_at)');
        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE vouchers MODIFY COLUMN type ENUM('percent', 'fixed') NOT NULL");
        }
    }
};
