<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    private const OLD = [
        'dispatch.offer_open_seconds' => '10',
        'dispatch.offer_decision_seconds' => '15',
    ];

    private const NEW = [
        'dispatch.offer_open_seconds' => '15',
        'dispatch.offer_decision_seconds' => '20',
    ];

    public function up(): void
    {
        $this->replaceExactPair('settings', null, self::OLD, self::NEW);

        DB::table(OperationalSettings::CITY_TABLE)->distinct()->pluck('city_id')
            ->each(fn ($cityId) => $this->replaceExactPair(
                OperationalSettings::CITY_TABLE,
                (int) $cityId,
                self::OLD,
                self::NEW,
            ));

        OperationalSettings::flush();
    }

    public function down(): void
    {
        $this->replaceExactPair('settings', null, self::NEW, self::OLD);

        DB::table(OperationalSettings::CITY_TABLE)->distinct()->pluck('city_id')
            ->each(fn ($cityId) => $this->replaceExactPair(
                OperationalSettings::CITY_TABLE,
                (int) $cityId,
                self::NEW,
                self::OLD,
            ));

        OperationalSettings::flush();
    }

    /** Chỉ đổi đúng bộ mặc định cũ; khu vực đã chỉnh một trong hai mốc được giữ nguyên. */
    private function replaceExactPair(string $table, ?int $cityId, array $from, array $to): void
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
