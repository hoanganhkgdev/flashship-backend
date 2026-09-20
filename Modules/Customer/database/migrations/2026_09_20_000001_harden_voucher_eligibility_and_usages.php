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
            $table->boolean('first_order_only')->default(false)->after('per_user_limit');
        });

        // Existing campaigns explicitly advertised for new accounts. Make the
        // rule enforceable by data instead of relying on the voucher name.
        DB::table('vouchers')
            ->whereIn('code', ['GIẢM 30% TÀI KHOẢN MỚI', 'ƯU ĐÃI NGƯỜI MỚI'])
            ->update([
                'first_order_only' => true,
                'per_user_limit' => 1,
            ]);

        // Remove usages whose order no longer exists, collapse any accidental
        // duplicates, then rebuild the cached counter before adding constraints.
        DB::table('voucher_usages')
            ->whereNotNull('order_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('orders')
                ->whereColumn('orders.id', 'voucher_usages.order_id'))
            ->delete();

        $duplicates = DB::table('voucher_usages')
            ->select('order_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('voucher_usages')
                ->where('order_id', $duplicate->order_id)
                ->where('id', '<>', $duplicate->keep_id)
                ->delete();
        }

        DB::table('vouchers')->update(['used_count' => 0]);
        DB::table('voucher_usages')
            ->select('voucher_id', DB::raw('COUNT(*) as usage_count'))
            ->groupBy('voucher_id')
            ->orderBy('voucher_id')
            ->each(function ($row) {
                DB::table('vouchers')->where('id', $row->voucher_id)->update([
                    'used_count' => $row->usage_count,
                ]);
            });

        Schema::table('voucher_usages', function (Blueprint $table) {
            $table->unique('order_id', 'voucher_usages_order_id_unique');
            $table->foreign('order_id', 'voucher_usages_order_id_foreign')
                ->references('id')
                ->on('orders')
                ->cascadeOnDelete();
        });

        // Keep redemption history when an account is deleted so global usage
        // counters remain auditable and cannot silently drift.
        Schema::table('voucher_usages', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('voucher_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // A voucher targeted at one account must never become a public voucher
        // merely because that account is deleted.
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('voucher_usages', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        DB::table('voucher_usages')->whereNull('user_id')->delete();
        Schema::table('voucher_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('voucher_usages', function (Blueprint $table) {
            $table->dropForeign('voucher_usages_order_id_foreign');
            $table->dropUnique('voucher_usages_order_id_unique');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('first_order_only');
        });
    }
};
