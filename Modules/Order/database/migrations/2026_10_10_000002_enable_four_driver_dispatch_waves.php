<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'dispatch.wave_size'],
            ['value' => '4', 'updated_at' => now(), 'created_at' => now()],
        );

        DB::table('settings')->where('key', 'market.wait_seconds_before_open')
            ->update(['value' => '90', 'updated_at' => now()]);

        if (DB::getSchemaBuilder()->hasTable(OperationalSettings::CITY_TABLE)) {
            DB::table(OperationalSettings::CITY_TABLE)
                ->where('key', 'market.wait_seconds_before_open')
                ->update(['value' => '90', 'updated_at' => now()]);

            $cityIds = DB::table('cities')->pluck('id');
            foreach ($cityIds as $cityId) {
                DB::table(OperationalSettings::CITY_TABLE)->updateOrInsert(
                    ['city_id' => $cityId, 'key' => 'dispatch.wave_size'],
                    ['value' => '4', 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }

        OperationalSettings::flush();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'dispatch.wave_size')->delete();
        if (DB::getSchemaBuilder()->hasTable(OperationalSettings::CITY_TABLE)) {
            DB::table(OperationalSettings::CITY_TABLE)->where('key', 'dispatch.wave_size')->delete();
        }
        OperationalSettings::flush();
    }
};
