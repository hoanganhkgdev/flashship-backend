<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cần Thơ (id=1): cập nhật 3 ca hiện có sang khung giờ mới đã chốt theo
        // dữ liệu đơn hàng thật (xem kế hoạch), đổi code 'night' -> 'evening'
        // vì khung giờ giờ là 18h-24h chứ không còn qua đêm 22h-6h nữa.
        DB::table('shifts')->where('code', 'morning')->update([
            'name' => 'Ca sáng', 'start_time' => '06:00:00', 'end_time' => '12:00:00',
        ]);
        DB::table('shifts')->where('code', 'afternoon')->update([
            'name' => 'Ca chiều', 'start_time' => '12:00:00', 'end_time' => '18:00:00',
        ]);
        DB::table('shifts')->where('code', 'night')->update([
            'code' => 'evening', 'name' => 'Ca tối', 'start_time' => '18:00:00', 'end_time' => '00:00:00',
        ]);

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique('shifts_code_unique');
            $table->dropForeign('shifts_city_id_foreign');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable(false)->change();
            $table->unique(['city_id', 'code']);
            $table->foreign('city_id')->references('id')->on('cities');
        });

        // Seed cùng 3 ca mặc định cho các khu vực còn lại (Rạch Giá, Phú Quốc)
        // — mỗi khu vực tự chỉnh/thêm bớt sau qua trang quản trị.
        $otherCityIds = DB::table('cities')->where('id', '!=', 1)->pluck('id');
        $defaults = [
            ['code' => 'morning',   'name' => 'Ca sáng',  'start_time' => '06:00:00', 'end_time' => '12:00:00'],
            ['code' => 'afternoon', 'name' => 'Ca chiều', 'start_time' => '12:00:00', 'end_time' => '18:00:00'],
            ['code' => 'evening',   'name' => 'Ca tối',   'start_time' => '18:00:00', 'end_time' => '00:00:00'],
        ];

        foreach ($otherCityIds as $cityId) {
            foreach ($defaults as $d) {
                DB::table('shifts')->insertOrIgnore([
                    'city_id'    => $cityId,
                    'code'       => $d['code'],
                    'name'       => $d['name'],
                    'start_time' => $d['start_time'],
                    'end_time'   => $d['end_time'],
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['city_id']);
            $table->dropUnique(['city_id', 'code']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->change();
        });

        DB::table('shifts')->where('city_id', '!=', 1)->delete();

        DB::table('shifts')->where('code', 'evening')->update(['code' => 'night']);

        Schema::table('shifts', function (Blueprint $table) {
            $table->unique('code');
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
        });
    }
};
