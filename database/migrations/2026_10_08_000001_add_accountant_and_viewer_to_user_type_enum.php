<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM('admin','subadmin','city_manager','call_center','accountant','viewer','driver','customer','shop') NOT NULL DEFAULT 'customer'");
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET user_type = 'call_center' WHERE user_type IN ('accountant', 'viewer')");
        DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM('admin','subadmin','city_manager','call_center','driver','customer','shop') NOT NULL DEFAULT 'customer'");
    }
};
