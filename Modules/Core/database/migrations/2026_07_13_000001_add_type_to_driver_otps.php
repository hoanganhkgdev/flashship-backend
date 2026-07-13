<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_otps', function (Blueprint $table) {
            // Tách OTP theo ngữ cảnh (register / forgot_password) — trước đây
            // AuthController truyền type nhưng OtpService bỏ qua nên OTP đăng ký
            // dùng được cho reset mật khẩu và ngược lại.
            $table->string('type', 30)->default('register')->after('otp');
        });
    }

    public function down(): void
    {
        Schema::table('driver_otps', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
