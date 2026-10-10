<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Services\OperationalSettings;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('viewed_at');
            $table->index(['result', 'expires_at'], 'dispatch_logs_pending_expiry_index');
        });

        $now = now();
        DB::table('settings')->updateOrInsert(
            ['key' => 'dispatch.fair_score_band'],
            ['value' => '8', 'group' => 'operations', 'created_at' => $now, 'updated_at' => $now],
        );

        foreach (DB::table('cities')->pluck('id') as $cityId) {
            DB::table(OperationalSettings::CITY_TABLE)->updateOrInsert(
                ['city_id' => $cityId, 'key' => 'dispatch.fair_score_band'],
                ['value' => '8', 'created_at' => $now, 'updated_at' => $now],
            );
        }

        // Chỉ đổi các giá trị mặc định cũ; cấu hình tuỳ chỉnh của từng khu vực
        // được giữ nguyên.
        DB::table('settings')->where('key', 'dispatch.offer_open_seconds')->where('value', '15')
            ->update(['value' => '10', 'updated_at' => $now]);
        DB::table('settings')->where('key', 'dispatch.offer_decision_seconds')->where('value', '30')
            ->update(['value' => '15', 'updated_at' => $now]);
        DB::table(OperationalSettings::CITY_TABLE)->where('key', 'dispatch.offer_open_seconds')->where('value', '15')
            ->update(['value' => '10', 'updated_at' => $now]);
        DB::table(OperationalSettings::CITY_TABLE)->where('key', 'dispatch.offer_decision_seconds')->where('value', '30')
            ->update(['value' => '15', 'updated_at' => $now]);

        OperationalSettings::flush();
    }

    public function down(): void
    {
        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            $table->dropIndex('dispatch_logs_pending_expiry_index');
            $table->dropColumn('expires_at');
        });

        DB::table(OperationalSettings::CITY_TABLE)->where('key', 'dispatch.fair_score_band')->delete();
        DB::table('settings')->where('key', 'dispatch.fair_score_band')->delete();
        DB::table('settings')->where('key', 'dispatch.offer_open_seconds')->where('value', '10')->update(['value' => '15']);
        DB::table('settings')->where('key', 'dispatch.offer_decision_seconds')->where('value', '15')->update(['value' => '30']);
        DB::table(OperationalSettings::CITY_TABLE)->where('key', 'dispatch.offer_open_seconds')->where('value', '10')->update(['value' => '15']);
        DB::table(OperationalSettings::CITY_TABLE)->where('key', 'dispatch.offer_decision_seconds')->where('value', '15')->update(['value' => '30']);
        OperationalSettings::flush();
    }
};
