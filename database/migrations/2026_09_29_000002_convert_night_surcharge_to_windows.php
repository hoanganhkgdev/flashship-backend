<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    private const KEY = 'pricing.night_windows';

    public function up(): void
    {
        $now = now();

        // Mỗi khu vực giữ đúng hai mức tiền đang áp dụng, chỉ chuyển sang dạng
        // khung giờ (23:00–01:00 và 01:00–04:00 như luật cũ).
        $global = DB::table('settings')->pluck('value', 'key');
        DB::table('settings')->insertOrIgnore([
            'key' => self::KEY,
            'value' => $this->windows($global),
            'group' => 'operations',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (DB::table(OperationalSettings::CITY_TABLE)->distinct()->pluck('city_id') as $cityId) {
            $values = DB::table(OperationalSettings::CITY_TABLE)->where('city_id', $cityId)->pluck('value', 'key');
            DB::table(OperationalSettings::CITY_TABLE)->insertOrIgnore([
                'city_id' => $cityId,
                'key' => self::KEY,
                'value' => $this->windows($values),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Dòng night_23_00/night_01_03 cũ để nguyên (code mới bỏ qua) để
        // rollback về bản trước vẫn đọc được đúng mức cũ.
        OperationalSettings::flush();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
        DB::table(OperationalSettings::CITY_TABLE)->where('key', self::KEY)->delete();
        OperationalSettings::flush();
    }

    private function windows(\Illuminate\Support\Collection $values): string
    {
        return json_encode([
            ['from' => '23:00', 'to' => '01:00', 'amount' => (int) ($values['pricing.night_23_00_amount'] ?? 5000)],
            ['from' => '01:00', 'to' => '04:00', 'amount' => (int) ($values['pricing.night_01_03_amount'] ?? 10000)],
        ]);
    }
};
