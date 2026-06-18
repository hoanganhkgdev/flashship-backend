<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM('admin','subadmin','city_manager','driver','customer','shop') NOT NULL DEFAULT 'customer'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM('admin','subadmin','driver','customer','shop') NOT NULL DEFAULT 'customer'");
    }
};
