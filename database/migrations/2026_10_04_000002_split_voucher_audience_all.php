<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bỏ đối tượng "Tất cả người dùng" của mã giảm giá: từ nay mã khách hàng là của khách hàng,
 * mã cửa hàng là của cửa hàng. Các mã đang là 'all' đều có danh sách dịch vụ của khách hàng
 * (Xe ôm, Lái hộ, Mua hộ...) và phần lớn lượt dùng là của khách, nên chuyển sang 'customer'.
 *
 * LƯU Ý: cửa hàng từng dùng một số mã này sẽ không còn dùng được nữa; muốn giữ ưu đãi cho
 * cửa hàng thì tạo mã riêng ở mục "Mã giảm giá cửa hàng".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vouchers')->where('audience', 'all')->update(['audience' => 'customer']);
    }

    public function down(): void
    {
        // Không khôi phục được: không còn biết mã nào từng là 'all'.
    }
};
