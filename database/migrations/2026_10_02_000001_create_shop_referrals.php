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
            // Shop giới thiệu shop: điểm thưởng tích luỹ để đổi voucher.
            $table->unsignedBigInteger('referred_by_shop_id')->nullable()->after('referred_by_driver_id');
            $table->unsignedInteger('reward_points')->default(0)->after('referred_by_shop_id');
            $table->index('referred_by_shop_id');
        });

        Schema::create('shop_referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_shop_id')->index();
            // Mỗi shop chỉ được tính giới thiệu một lần.
            $table->unsignedBigInteger('referred_shop_id')->unique();
            $table->string('status', 16)->default('pending')->index(); // pending | rewarded | rejected
            $table->unsignedInteger('points')->nullable();
            $table->unsignedSmallInteger('required_orders')->default(1);
            $table->unsignedBigInteger('welcome_voucher_id')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
        });

        // Sổ cái điểm: mọi lần cộng/trừ đều có một dòng, reference duy nhất chống ghi trùng.
        Schema::create('shop_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->string('type', 24); // referral | redeem | adjust
            $table->integer('points'); // dương = cộng, âm = trừ
            $table->unsignedInteger('balance_after');
            $table->string('description')->nullable();
            $table->string('reference', 64)->nullable()->unique();
            $table->timestamps();
        });

        // Danh mục voucher đổi bằng điểm do admin cấu hình.
        Schema::create('point_rewards', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->unsignedInteger('points_cost');
            $table->string('type', 16)->default('fixed'); // fixed | percent | freeship
            $table->unsignedInteger('value')->default(0);
            $table->unsignedInteger('min_order_value')->nullable();
            $table->unsignedInteger('max_discount')->nullable();
            $table->unsignedSmallInteger('valid_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Mã giới thiệu cho shop đã có sẵn (dùng chung cột với tài xế, duy nhất toàn bảng).
        DB::table('users')->where('user_type', 'shop')->whereNull('referral_code')->orderBy('id')
            ->each(fn ($shop) => DB::table('users')->where('id', $shop->id)
                ->update(['referral_code' => ReferralService::generateUniqueCode()]));

        // Mẫu ban đầu để vận hành có thứ đổi ngay; admin sửa/xoá trong trang quản trị.
        DB::table('point_rewards')->insert([
            ['name' => 'Giảm 10.000đ phí ship', 'points_cost' => 50, 'type' => 'fixed', 'value' => 10000, 'min_order_value' => null, 'max_discount' => null, 'valid_days' => 30, 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Giảm 25.000đ phí ship', 'points_cost' => 100, 'type' => 'fixed', 'value' => 25000, 'min_order_value' => null, 'max_discount' => null, 'valid_days' => 30, 'is_active' => true, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Miễn phí ship (tối đa 40.000đ)', 'points_cost' => 150, 'type' => 'freeship', 'value' => 0, 'min_order_value' => null, 'max_discount' => 40000, 'valid_days' => 30, 'is_active' => true, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('point_rewards');
        Schema::dropIfExists('shop_point_transactions');
        Schema::dropIfExists('shop_referrals');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['referred_by_shop_id']);
            $table->dropColumn(['referred_by_shop_id', 'reward_points']);
        });
    }
};
