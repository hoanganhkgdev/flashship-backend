<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->decimal('max_distance_km', 8, 2)->nullable()->after('max_discount');
        });

        DB::table('vouchers')
            ->where('code', 'ƯU ĐÃI NGƯỜI MỚI')
            ->update(['max_distance_km' => 10]);

        $testCustomerId = DB::table('users')
            ->where('phone', '0981483284')
            ->where('user_type', 'customer')
            ->value('id');

        if ($testCustomerId) {
            DB::table('vouchers')->where('code', 'FREESHIP')->update([
                'audience' => 'customer',
                'user_id' => $testCustomerId,
                'city_id' => null,
                'max_discount' => 25_000,
                'max_distance_km' => 10,
            ]);
        }

        // Old cancellation paths left usages behind. Cancelled orders must not
        // consume a voucher use or contribute to the cached global counter.
        DB::table('voucher_usages')
            ->whereIn('order_id', DB::table('orders')->select('id')->where('status', 'cancelled'))
            ->delete();

        DB::table('vouchers')->update(['used_count' => 0]);
        DB::table('voucher_usages')
            ->select('voucher_id', DB::raw('COUNT(*) as usage_count'))
            ->groupBy('voucher_id')
            ->orderBy('voucher_id')
            ->each(fn ($row) => DB::table('vouchers')->where('id', $row->voucher_id)
                ->update(['used_count' => $row->usage_count]));
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('max_distance_km');
        });
    }
};
