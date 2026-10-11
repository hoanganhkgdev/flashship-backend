<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    public function up(): void
    {
        // Migration 000004 từng được chạy ở một số môi trường phát triển khi
        // còn suy ra mode từ market.enabled. Chuẩn hóa lại để lần rollout đầu
        // tuyệt đối không vô tình mở Chợ đơn ở bất kỳ khu vực nào.
        DB::table('settings')->where('key', 'dispatch.mode')->update([
            'value' => 'system_assigned',
            'updated_at' => now(),
        ]);

        if (Schema::hasTable(OperationalSettings::CITY_TABLE)) {
            DB::table(OperationalSettings::CITY_TABLE)
                ->where('key', 'dispatch.mode')
                ->update(['value' => 'system_assigned', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Không thể khôi phục an toàn giá trị cũ theo từng khu vực.
    }
};
