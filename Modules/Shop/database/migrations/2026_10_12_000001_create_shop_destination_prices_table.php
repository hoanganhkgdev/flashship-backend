<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_destination_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id');
            $table->string('name');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('radius_km', 4, 1)->default(1.5);
            $table->unsignedInteger('fee')->comment('Giá áp dụng cho đơn');
            $table->unsignedInteger('fee_max')->nullable()->comment('Cận trên của khoảng giá, chỉ để tham khảo');
            $table->boolean('is_origin')->default(false)->comment('Điểm xuất phát: đơn lấy hàng ngoài vùng này dùng giá theo km');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['city_id', 'is_active']);
            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');
        });

        $this->seedPhuQuoc();
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_destination_prices');
    }

    /**
     * Bảng giá shop Phú Quốc (xuất phát từ nội ô Dương Đông). Toạ độ tra bằng
     * Google Geocoding nên là xấp xỉ; giá dạng khoảng lấy cận dưới, cận trên
     * lưu ở fee_max. Bãi Trường, Bến Tràm, Vòng xoay mới chưa có toạ độ đáng tin
     * nên chưa nhập — thêm trong trang quản trị.
     */
    private function seedPhuQuoc(): void
    {
        $cityId = DB::table('cities')->where('name', 'Phú Quốc')->value('id');
        if (! $cityId) {
            return;
        }

        // [tên, lat, lng, bán kính km, giá K, giá cao K, điểm xuất phát]
        $zones = [
            ['Nội ô Dương Đông', 10.2185, 103.9640, 2.5, 10, null, true],
            ['CMT8', 10.22887, 103.97958, 1.2, 15, null],
            ['67HA', 10.24288, 103.9593, 1.2, 20, null],
            ['Búng Gội', 10.27228, 103.96445, 1.8, 30, null],
            ['Ông Lang', 10.26715, 103.9405, 1.8, 40, 45],
            ['Cửa Cạn', 10.30437, 103.9028, 2.5, 60, 80],
            ['Grand World', 10.32832, 103.86276, 2.5, 100, null],
            ['Gành Dầu', 10.34819, 103.9028, 2.5, 130, null],
            ['Holiday', 10.20509, 103.96402, 1.2, 15, null],
            ['Cầu Bà Kèo', 10.1946, 103.9638, 1.2, 20, null],
            ['Cầu Bà Phong', 10.18513, 103.9684, 1.8, 25, null],
            ['Suối Mây', 10.1852, 104.00143, 1.8, 30, 35],
            ['Chợ Đường Bào', 10.15183, 103.97949, 1.8, 50, null],
            ['Sân bay', 10.16282, 103.99801, 1.8, 55, null],
            ['Địa Trung Hải', 10.03298, 104.00471, 2.5, 120, null],
            ['Cây Thông Ngoài', 10.25232, 103.98206, 1.8, 25, 40],
            ['Cây Thông Trong', 10.24644, 104.00486, 1.8, 35, 40],
            ['Suối Đá Bàn', 10.24545, 104.02703, 1.8, 30, 45],
            ['K8', 10.27144, 103.995, 1.8, 35, 40],
            ['Rạch Vẹm', 10.36049, 103.9347, 2.5, 110, null],
            ['Bãi Thơm', 10.38839, 103.99567, 2.5, 150, 180],
            ['Cổng chào Suối Đá', 10.2119, 103.95934, 1.0, 20, null],
            ['Ngã 3 số 10', 10.17513, 104.01706, 1.8, 45, null],
            ['Hàm Ninh', 10.18093, 104.04846, 2.5, 70, null],
            ['Chùa Hộ Quốc', 10.11013, 104.02889, 2.5, 90, null],
            ['Suối Lớn', 10.07825, 104.01656, 2.5, 100, null],
            ['Cầu Sấu', 10.06387, 104.01746, 2.5, 110, null],
            ['An Thới', 10.01505, 104.01457, 2.5, 130, null],
        ];

        $now = now();
        DB::table('shop_destination_prices')->insert(array_map(fn (array $z) => [
            'city_id' => $cityId,
            'name' => $z[0],
            'lat' => $z[1],
            'lng' => $z[2],
            'radius_km' => $z[3],
            'fee' => $z[4] * 1000,
            'fee_max' => $z[5] ? $z[5] * 1000 : null,
            'is_origin' => $z[6] ?? false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $zones));
    }
};
