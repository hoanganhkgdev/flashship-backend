<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Chỉ đổi giá trị mặc định cũ. Khu vực nào đã chủ động tùy chỉnh
        // sang con số khác thì giữ nguyên quyết định vận hành của khu vực đó.
        DB::table('settings')
            ->where('key', 'dispatch.wait_cap_minutes')
            ->where('value', '480')
            ->update(['value' => '240', 'updated_at' => $now]);
        DB::table(OperationalSettings::CITY_TABLE)
            ->where('key', 'dispatch.wait_cap_minutes')
            ->where('value', '480')
            ->update(['value' => '240', 'updated_at' => $now]);

        OperationalSettings::flush();
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'dispatch.wait_cap_minutes')
            ->where('value', '240')
            ->update(['value' => '480', 'updated_at' => now()]);
        DB::table(OperationalSettings::CITY_TABLE)
            ->where('key', 'dispatch.wait_cap_minutes')
            ->where('value', '240')
            ->update(['value' => '480', 'updated_at' => now()]);

        OperationalSettings::flush();
    }
};
