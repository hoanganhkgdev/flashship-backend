<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'market.wait_seconds_before_open'],
            ['value' => '180', 'updated_at' => now(), 'created_at' => now()],
        );

        if (Schema::hasTable(OperationalSettings::CITY_TABLE)) {
            DB::table(OperationalSettings::CITY_TABLE)
                ->where('key', 'market.wait_seconds_before_open')
                ->update(['value' => '180', 'updated_at' => now()]);
        }

        OperationalSettings::flush();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'market.wait_seconds_before_open')
            ->update(['value' => '90', 'updated_at' => now()]);
        if (Schema::hasTable(OperationalSettings::CITY_TABLE)) {
            DB::table(OperationalSettings::CITY_TABLE)
                ->where('key', 'market.wait_seconds_before_open')
                ->update(['value' => '90', 'updated_at' => now()]);
        }
        OperationalSettings::flush();
    }
};
