<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng có sẵn dùng khoá chính ghép (shift_id, user_id) kiểu pivot mặc
        // định, được 2 FK dựa vào — phải bỏ FK trước mới drop được primary.
        // Đổi sang khoá chính tự tăng để dùng chuẩn qua Eloquent, giữ lại
        // unique ghép để không cho đăng ký trùng 1 ca 2 lần.
        Schema::table('shift_user', function (Blueprint $table) {
            $table->dropForeign('shift_user_shift_id_foreign');
            $table->dropForeign('shift_user_user_id_foreign');
            $table->dropPrimary(['shift_id', 'user_id']);
        });

        Schema::table('shift_user', function (Blueprint $table) {
            $table->id()->first();
            $table->timestamps();
            $table->unique(['shift_id', 'user_id']);
            $table->foreign('shift_id')->references('id')->on('shifts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_user', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropForeign(['user_id']);
            $table->dropUnique(['shift_id', 'user_id']);
            $table->dropColumn(['id', 'created_at', 'updated_at']);
        });

        Schema::table('shift_user', function (Blueprint $table) {
            $table->primary(['shift_id', 'user_id']);
            $table->foreign('shift_id')->references('id')->on('shifts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
