<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Core\Models\Voucher;
use Modules\Core\Services\OperationalSettings;
use Modules\Order\Models\Order;
use Modules\Shop\Models\PointReward;
use Modules\Shop\Models\ShopReferral;
use Modules\Shop\Services\ShopReferralService;
use Tests\TestCase;

/** Chạy trên MySQL test (xem TestCase::mysqlTestConnection); mỗi test nằm trong 1 transaction và rollback. */
class ShopReferralTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql' => self::mysqlTestConnection(), 'database.default' => 'mysql']);
        DB::purge('mysql');
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        OperationalSettings::flush();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        OperationalSettings::flush();

        parent::tearDown();
    }

    public function test_every_shop_gets_a_unique_code_and_is_found_by_it(): void
    {
        $a = $this->makeShop();
        $b = $this->makeShop();

        $this->assertNotEmpty($a->fresh()->referral_code);
        $this->assertNotSame($a->fresh()->referral_code, $b->fresh()->referral_code);
        $this->assertSame($a->id, ShopReferralService::findShopByCode(' '.strtolower($a->fresh()->referral_code))->id);
        $this->assertNull(ShopReferralService::findShopByCode('ZZZZZZ'));

        $a->update(['status' => 0]);
        $this->assertNull(ShopReferralService::findShopByCode($a->fresh()->referral_code));
    }

    public function test_attach_creates_pending_referral_and_blocks_self_referral(): void
    {
        $referrer = $this->makeShop();
        $new = $this->makeShop();
        ShopReferralService::attachShop($new, $referrer->fresh()->referral_code);

        $this->assertSame($referrer->id, (int) $new->fresh()->referred_by_shop_id);
        $this->assertSame('pending', ShopReferral::where('referred_shop_id', $new->id)->value('status'));

        // Cùng một người nhưng nhập số khác định dạng (84xxx thay vì 0xxx).
        $same = $this->makeShop('84'.substr($referrer->phone, 1));
        ShopReferralService::attachShop($same, $referrer->fresh()->referral_code);
        $this->assertNull($same->fresh()->referred_by_shop_id);
        $this->assertSame(0, ShopReferral::where('referred_shop_id', $same->id)->count());
    }

    public function test_referrer_gets_points_once_and_new_shop_gets_welcome_voucher(): void
    {
        OperationalSettings::put([
            'shop_referral.points' => 120,
            'shop_referral.min_orders' => 2,
            'shop_referral.welcome_amount' => 15000,
        ], $this->cityId());
        [$referrer, $new] = $this->referredShop();
        $driver = $this->makeDriver();

        ShopReferralService::onOrderCompleted($this->completedOrder($new, $driver));
        $this->assertSame('pending', ShopReferral::where('referred_shop_id', $new->id)->value('status'));
        $this->assertSame(0, (int) $referrer->fresh()->reward_points);

        $second = $this->completedOrder($new, $driver);
        ShopReferralService::onOrderCompleted($second);
        ShopReferralService::onOrderCompleted($second); // chạy lại không cộng trùng

        $referral = ShopReferral::where('referred_shop_id', $new->id)->first();
        $this->assertSame('rewarded', $referral->status);
        $this->assertSame(120, (int) $referral->points);
        $this->assertSame(120, (int) $referrer->fresh()->reward_points);
        $this->assertSame(1, DB::table('shop_point_transactions')->where('reference', "shop_ref_{$new->id}")->count());

        $welcome = Voucher::find($referral->welcome_voucher_id);
        $this->assertSame($new->id, (int) $welcome->user_id);
        $this->assertSame(15000, (int) $welcome->value);
        $this->assertSame(1, (int) $welcome->usage_limit);
    }

    public function test_zero_points_and_zero_welcome_still_close_the_referral(): void
    {
        OperationalSettings::put(['shop_referral.points' => 0, 'shop_referral.welcome_amount' => 0, 'shop_referral.min_orders' => 1], $this->cityId());
        [$referrer, $new] = $this->referredShop();

        ShopReferralService::onOrderCompleted($this->completedOrder($new, $this->makeDriver()));

        $referral = ShopReferral::where('referred_shop_id', $new->id)->first();
        $this->assertSame('rewarded', $referral->status);
        $this->assertNull($referral->welcome_voucher_id);
        $this->assertSame(0, (int) $referrer->fresh()->reward_points);
    }

    public function test_rejected_referral_is_never_rewarded(): void
    {
        [$referrer, $new] = $this->referredShop();
        $referral = ShopReferral::where('referred_shop_id', $new->id)->first();

        $this->assertTrue(ShopReferralService::reject($referral, 'Shop ảo'));
        $this->assertFalse(ShopReferralService::reject($referral, 'lần hai'));
        $this->assertFalse(ShopReferralService::grantReward($referral, $new->city_id));
        ShopReferralService::onOrderCompleted($this->completedOrder($new, $this->makeDriver()));

        $this->assertSame('rejected', $referral->fresh()->status);
        $this->assertSame(0, (int) $referrer->fresh()->reward_points);
    }

    public function test_redeem_spends_points_and_issues_a_private_voucher(): void
    {
        $shop = $this->makeShop();
        DB::transaction(fn () => ShopReferralService::addPoints($shop->id, 130, 'adjust', 'Test', 'seed_'.uniqid()));
        $reward = PointReward::create(['name' => 'Giảm 20k', 'points_cost' => 100, 'type' => 'fixed', 'value' => 20000, 'valid_days' => 10, 'is_active' => true]);

        $result = ShopReferralService::redeem($shop->fresh(), $reward);

        $this->assertSame(30, $result['balance']);
        $this->assertSame(30, (int) $shop->fresh()->reward_points);
        $voucher = $result['voucher'];
        $this->assertSame($shop->id, (int) $voucher->user_id);
        $this->assertSame('shop', $voucher->audience);
        $this->assertSame(20000, (int) $voucher->value);
        $this->assertTrue($voucher->expires_at->isFuture());
        $this->assertSame(-100, (int) DB::table('shop_point_transactions')->where('shop_id', $shop->id)->where('type', 'redeem')->value('points'));
    }

    public function test_redeem_fails_without_enough_points_or_for_inactive_reward(): void
    {
        $shop = $this->makeShop();
        $reward = PointReward::create(['name' => 'Giảm 20k', 'points_cost' => 100, 'type' => 'fixed', 'value' => 20000, 'valid_days' => 10, 'is_active' => true]);

        try {
            ShopReferralService::redeem($shop->fresh(), $reward);
            $this->fail('Phải báo không đủ điểm');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('points', $e->errors());
        }
        $this->assertSame(0, Voucher::where('user_id', $shop->id)->count());

        DB::table('users')->where('id', $shop->id)->update(['reward_points' => 500]);
        $reward->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        ShopReferralService::redeem($shop->fresh(), $reward->fresh());
    }

    // ─── helpers ───────────────────────────────────────────────────────────────

    private function cityId(): int
    {
        return (int) (DB::table('cities')->value('id')
            ?: DB::table('cities')->insertGetId(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]));
    }

    /** @return array{0: User, 1: User} */
    private function referredShop(): array
    {
        $referrer = $this->makeShop();
        $new = $this->makeShop();
        ShopReferralService::attachShop($new, $referrer->fresh()->referral_code);

        return [$referrer, $new];
    }

    private function makeShop(?string $phone = null): User
    {
        return $this->makeUser('shop', $phone);
    }

    private function makeDriver(): User
    {
        return $this->makeUser('driver');
    }

    private function makeUser(string $type, ?string $phone = null): User
    {
        return User::create([
            'name' => "ShopRef {$type}",
            'email' => 'shopref-'.uniqid().'@test.local',
            'phone' => $phone ?? '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => $type,
            'status' => 1,
            'city_id' => $this->cityId(),
        ]);
    }

    private function completedOrder(User $shop, User $driver): Order
    {
        $id = DB::table('orders')->insertGetId([
            'code' => 'SRF'.uniqid(),
            'service_type' => 'delivery',
            'sender_platform_id' => $shop->id,
            'delivery_man_id' => $driver->id,
            'platform' => 'shop_app',
            'status' => 'completed',
            'city_id' => $shop->city_id,
            'shipping_fee' => 0,
            'bonus_fee' => 0,
            'is_freeship' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::findOrFail($id);
    }
}
