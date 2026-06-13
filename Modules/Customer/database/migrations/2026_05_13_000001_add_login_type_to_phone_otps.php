<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE phone_otps MODIFY type ENUM('register', 'reset_password', 'login') NOT NULL");

            return;
        }

        // SQLite không hỗ trợ MODIFY COLUMN, phải recreate table
        DB::statement("CREATE TABLE phone_otps_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone VARCHAR(20) NOT NULL,
            code VARCHAR(6) NOT NULL,
            type VARCHAR(20) NOT NULL CHECK(type IN ('register', 'reset_password', 'login')),
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )");

        DB::statement("INSERT INTO phone_otps_new SELECT * FROM phone_otps");
        DB::statement("DROP TABLE phone_otps");
        DB::statement("ALTER TABLE phone_otps_new RENAME TO phone_otps");
        DB::statement("CREATE INDEX phone_otps_phone_type_index ON phone_otps (phone, type)");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("DELETE FROM phone_otps WHERE type = 'login'");
            DB::statement("ALTER TABLE phone_otps MODIFY type ENUM('register', 'reset_password') NOT NULL");

            return;
        }

        DB::statement("CREATE TABLE phone_otps_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone VARCHAR(20) NOT NULL,
            code VARCHAR(6) NOT NULL,
            type VARCHAR(20) NOT NULL CHECK(type IN ('register', 'reset_password')),
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )");

        DB::statement("INSERT INTO phone_otps_new SELECT * FROM phone_otps WHERE type != 'login'");
        DB::statement("DROP TABLE phone_otps");
        DB::statement("ALTER TABLE phone_otps_new RENAME TO phone_otps");
        DB::statement("CREATE INDEX phone_otps_phone_type_index ON phone_otps (phone, type)");
    }
};
