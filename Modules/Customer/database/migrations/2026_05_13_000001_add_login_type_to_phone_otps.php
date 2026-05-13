<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE phone_otps MODIFY COLUMN type ENUM('register', 'reset_password', 'login') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE phone_otps MODIFY COLUMN type ENUM('register', 'reset_password') NOT NULL");
    }
};
