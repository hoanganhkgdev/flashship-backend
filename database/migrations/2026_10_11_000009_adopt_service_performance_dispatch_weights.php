<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    private const OLD = [
        'dispatch.score_weight' => '15',
        'dispatch.wait_weight' => '42.5',
        'dispatch.distance_weight' => '42.5',
    ];

    private const NEW = [
        'dispatch.score_weight' => '25',
        'dispatch.wait_weight' => '30',
        'dispatch.distance_weight' => '45',
    ];

    public function up(): void
    {
        $this->replaceExactSet('settings', null, self::OLD, self::NEW);

        DB::table(OperationalSettings::CITY_TABLE)->distinct()->pluck('city_id')
            ->each(fn ($cityId) => $this->replaceExactSet(
                OperationalSettings::CITY_TABLE,
                (int) $cityId,
                self::OLD,
                self::NEW,
            ));

        OperationalSettings::flush();
    }

    public function down(): void
    {
        $this->replaceExactSet('settings', null, self::NEW, self::OLD);

        DB::table(OperationalSettings::CITY_TABLE)->distinct()->pluck('city_id')
            ->each(fn ($cityId) => $this->replaceExactSet(
                OperationalSettings::CITY_TABLE,
                (int) $cityId,
                self::NEW,
                self::OLD,
            ));

        OperationalSettings::flush();
    }

    /** Chỉ thay khi cả ba giá trị vẫn đúng bộ mặc định cũ; cấu hình riêng được giữ. */
    private function replaceExactSet(string $table, ?int $cityId, array $from, array $to): void
    {
        $query = DB::table($table)->whereIn('key', array_keys($from));
        if ($cityId !== null) {
            $query->where('city_id', $cityId);
        }

        $current = $query->pluck('value', 'key')->map(fn ($value) => (string) $value)->all();
        foreach ($from as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                return;
            }
        }

        foreach ($to as $key => $value) {
            $update = DB::table($table)->where('key', $key);
            if ($cityId !== null) {
                $update->where('city_id', $cityId);
            }
            $update->update(['value' => $value, 'updated_at' => now()]);
        }
    }
};
