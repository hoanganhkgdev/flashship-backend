<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach (OperationalSettings::DEFAULTS as $key => $value) {
            $rows[] = [
                'key' => $key,
                'value' => $value,
                'group' => 'operations',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Không ghi đè nếu môi trường đã chủ động tạo cấu hình trước khi chạy
        // migration (ví dụ rollout nhiều instance không đồng thời).
        DB::table('settings')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys(OperationalSettings::DEFAULTS))->delete();
    }
};
