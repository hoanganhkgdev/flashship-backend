<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Driver\Services\ReferralService;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->unique()->after('phone');
            $table->unsignedBigInteger('referred_by_driver_id')->nullable()->after('referral_code');
            $table->index('referred_by_driver_id');
        });

        Schema::create('driver_referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id')->index();
            // Mỗi shop chỉ được tính giới thiệu một lần.
            $table->unsignedBigInteger('shop_id')->unique();
            $table->string('status', 16)->default('pending')->index(); // pending | rewarded | rejected
            $table->unsignedInteger('reward_amount')->nullable();
            $table->unsignedSmallInteger('required_orders')->default(1);
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
        });

        // Cấp mã cho tài xế đã có sẵn.
        DB::table('users')->where('user_type', 'driver')->whereNull('referral_code')->orderBy('id')
            ->each(fn ($driver) => DB::table('users')->where('id', $driver->id)
                ->update(['referral_code' => ReferralService::generateUniqueCode()]));
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_referrals');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['referred_by_driver_id']);
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referred_by_driver_id']);
        });
    }
};
