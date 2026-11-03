<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Khu vực phát sinh của khoản nợ. Trước đây báo cáo lấy khu vực HIỆN TẠI của tài xế, nên tài xế đổi khu vực thì
 * toàn bộ lịch sử nợ "đi theo". Nullable: code cũ vẫn chạy được trong lúc deploy; code mới ghi khi tạo nợ.
 *
 * Điền cho nợ cũ theo nguồn chính xác nhất có được:
 *  1. Phạt offline: khu vực của ca (driver_shift_offline_penalties → shifts.city_id).
 *  2. Phí tuần: ghi chú kết thúc bằng "(<tên khu vực>)" (GenerateWeeklyFeesCommand).
 *  3. Hoa hồng ngày: ghi chú kết thúc bằng "— <tên khu vực>" (GenerateDailyCommissionCommand).
 *  4. Còn lại: khu vực hiện tại của tài xế (như cách tính cũ).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('driver_debts', 'city_id')) {
            Schema::table('driver_debts', function (Blueprint $table) {
                $table->unsignedBigInteger('city_id')->nullable()->after('driver_id');
                $table->index(['city_id', 'driver_id']);
            });
        }

        DB::statement('
            UPDATE driver_debts d
            JOIN driver_shift_offline_penalties p ON p.debt_id = d.id
            JOIN shifts s ON s.id = p.shift_id
            SET d.city_id = s.city_id
            WHERE d.city_id IS NULL AND s.city_id IS NOT NULL
        ');

        foreach (DB::table('cities')->get(['id', 'name']) as $city) {
            DB::table('driver_debts')->whereNull('city_id')->whereNull('ref_id')
                ->where('note', 'like', '%('.$city->name.')')
                ->update(['city_id' => $city->id]);
            DB::table('driver_debts')->whereNull('city_id')->where('ref_id', 'like', 'daily\_commission\_%')
                ->where('note', 'like', '%— '.$city->name)
                ->update(['city_id' => $city->id]);
        }

        DB::statement('
            UPDATE driver_debts d
            JOIN users u ON u.id = d.driver_id
            SET d.city_id = u.city_id
            WHERE d.city_id IS NULL
        ');
    }

    public function down(): void
    {
        if (Schema::hasColumn('driver_debts', 'city_id')) {
            Schema::table('driver_debts', function (Blueprint $table) {
                $table->dropIndex(['city_id', 'driver_id']);
                $table->dropColumn('city_id');
            });
        }
    }
};
