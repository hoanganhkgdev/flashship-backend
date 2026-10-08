<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('settings')->updateOrInsert(
            ['key' => 'dispatch.offer_receipt_seconds'],
            ['value' => '8', 'created_at' => $now, 'updated_at' => $now],
        );
        DB::table('settings')->where('key', 'dispatch.offer_open_seconds')
            ->where('value', '25')->update(['value' => '15', 'updated_at' => $now]);

        foreach (DB::table('cities')->pluck('id') as $cityId) {
            DB::table(OperationalSettings::CITY_TABLE)->updateOrInsert(
                ['city_id' => $cityId, 'key' => 'dispatch.offer_receipt_seconds'],
                ['value' => '8', 'created_at' => $now, 'updated_at' => $now],
            );
        }
        DB::table(OperationalSettings::CITY_TABLE)->where('key', 'dispatch.offer_open_seconds')
            ->where('value', '25')->update(['value' => '15', 'updated_at' => $now]);

        OperationalSettings::flush();
    }

    public function down(): void
    {
        DB::table(OperationalSettings::CITY_TABLE)->where('key', 'dispatch.offer_receipt_seconds')->delete();
        DB::table('settings')->where('key', 'dispatch.offer_receipt_seconds')->delete();
        OperationalSettings::flush();
    }
};
