<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('settings')->insertOrIgnore([
            'key' => 'dispatch.mode',
            'value' => 'system_assigned',
            'group' => 'operations',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (Schema::hasTable(OperationalSettings::CITY_TABLE)) {
            // Deploy lần đầu tuyệt đối không suy diễn từ market.enabled cũ:
            // Chợ đơn fallback cũ không đồng nghĩa với luồng tự chọn mới.
            $rows = DB::table('cities')->pluck('id')->map(fn ($cityId) => [
                'city_id' => $cityId,
                'key' => 'dispatch.mode',
                'value' => 'system_assigned',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($rows !== []) {
                DB::table(OperationalSettings::CITY_TABLE)->insertOrIgnore($rows);
            }
        }

        OperationalSettings::flush();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'dispatch.mode')->delete();
        if (Schema::hasTable(OperationalSettings::CITY_TABLE)) {
            DB::table(OperationalSettings::CITY_TABLE)->where('key', 'dispatch.mode')->delete();
        }
        OperationalSettings::flush();
    }
};
