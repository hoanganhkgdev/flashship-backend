<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $cityId = DB::table('cities')
            ->where('slug', 'phu-quoc')
            ->orWhere('name', 'Phú Quốc')
            ->value('id');

        if (! $cityId) {
            $cityId = DB::table('cities')->insertGetId([
                'name' => 'Phú Quốc',
                'slug' => 'phu-quoc',
                'lat' => 10.2185,
                'lng' => 103.9640,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // [tên, lat, lng, bán kính km, giá thấp, giá cao, điểm xuất phát]
        $zones = [
            ['Nội ô Dương Đông', 10.2185, 103.9640, 2.5, 10000, null, true],
            ['CMT8', 10.22887, 103.97958, 1.2, 15000, null, false],
            ['67HA', 10.24288, 103.9593, 1.2, 20000, null, false],
            ['Búng Gội', 10.27228, 103.96445, 1.8, 30000, null, false],
            ['Ông Lang', 10.26715, 103.9405, 1.8, 40000, 45000, false],
            ['Cửa Cạn', 10.30437, 103.9028, 2.5, 60000, 80000, false],
            ['Grand World', 10.32832, 103.86276, 2.5, 100000, null, false],
            ['Gành Dầu', 10.34819, 103.9028, 2.5, 130000, null, false],
            ['Holiday', 10.20509, 103.96402, 1.2, 15000, null, false],
            ['Cầu Bà Kèo', 10.1946, 103.9638, 1.2, 20000, null, false],
            ['Cầu Bà Phong', 10.18513, 103.9684, 1.8, 25000, null, false],
            ['Suối Mây', 10.1852, 104.00143, 1.8, 30000, 35000, false],
            ['Chợ Đường Bào', 10.15183, 103.97949, 1.8, 50000, null, false],
            ['Sân bay', 10.16282, 103.99801, 1.8, 55000, null, false],
            // Dùng tâm khu Sonasea/Bãi Trường phía nam sân bay; kết quả geocode
            // chung "Bãi Trường" nằm quá gần Cầu Bà Phong và gây chồng vùng sai.
            ['Bãi Trường', 10.1304354, 103.9806225, 2.5, 70000, null, false],
            ['Địa Trung Hải', 10.03298, 104.00471, 2.5, 120000, null, false],
            ['Bến Tràm', 10.2365753, 104.0059899, 1.8, 15000, null, false],
            ['Cây Thông Ngoài', 10.25232, 103.98206, 1.8, 25000, 40000, false],
            ['Cây Thông Trong', 10.24644, 104.00486, 1.8, 35000, 40000, false],
            ['Suối Đá Bàn', 10.24545, 104.02703, 1.8, 30000, 45000, false],
            ['K8', 10.27144, 103.995, 1.8, 35000, 40000, false],
            ['Vòng xoay mới', 10.289879, 103.98402, 1.8, 50000, null, false],
            ['Rạch Vẹm', 10.36049, 103.9347, 2.5, 110000, null, false],
            ['Bãi Thơm', 10.38839, 103.99567, 2.5, 150000, 180000, false],
            ['Cổng chào Suối Đá', 10.2119, 103.95934, 1.0, 20000, null, false],
            ['Ngã 3 số 10', 10.17513, 104.01706, 1.8, 45000, null, false],
            ['Hàm Ninh', 10.18093, 104.04846, 2.5, 70000, null, false],
            ['Chùa Hộ Quốc', 10.11013, 104.02889, 2.5, 90000, null, false],
            ['Suối Lớn', 10.07825, 104.01656, 2.5, 100000, null, false],
            ['Cầu Sấu', 10.06387, 104.01746, 2.5, 110000, null, false],
            ['An Thới', 10.01505, 104.01457, 2.5, 130000, null, false],
        ];

        foreach ($zones as [$name, $lat, $lng, $radius, $fee, $feeMax, $isOrigin]) {
            $key = ['city_id' => $cityId, 'name' => $name];
            $values = [
                'lat' => $lat,
                'lng' => $lng,
                'radius_km' => $radius,
                'fee' => $fee,
                'fee_max' => $feeMax,
                'is_origin' => $isOrigin,
                'is_active' => true,
                'updated_at' => $now,
            ];

            if (DB::table('shop_destination_prices')->where($key)->exists()) {
                DB::table('shop_destination_prices')->where($key)->update($values);
            } else {
                DB::table('shop_destination_prices')->insert($key + $values + ['created_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // Dữ liệu giá có thể đã được admin chỉnh sau khi migrate; không xoá khi rollback.
    }
};
