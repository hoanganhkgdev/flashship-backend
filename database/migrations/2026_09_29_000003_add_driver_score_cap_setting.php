<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    private const KEY = 'driver_score.max_score';

    public function up(): void
    {
        // Trần điểm trước đây cố định 140 trong code — mỗi khu vực bắt đầu đúng mức đó.
        $now = now();
        $rows = DB::table(OperationalSettings::CITY_TABLE)->distinct()->pluck('city_id')
            ->map(fn ($cityId) => [
                'city_id' => $cityId,
                'key' => self::KEY,
                'value' => '140',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        DB::table(OperationalSettings::CITY_TABLE)->insertOrIgnore($rows);
        OperationalSettings::flush();
    }

    public function down(): void
    {
        DB::table(OperationalSettings::CITY_TABLE)->where('key', self::KEY)->delete();
        OperationalSettings::flush();
    }
};
