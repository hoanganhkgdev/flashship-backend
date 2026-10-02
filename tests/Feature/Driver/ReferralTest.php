<?php

namespace Tests\Feature\Driver;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Models\DriverReferral;
use Modules\Driver\Models\DriverWallet;
use Modules\Driver\Services\ReferralService;
use Modules\Order\Models\Order;
use Tests\TestCase;

/** Chạy trên MySQL test (xem TestCase::mysqlTestConnection); mỗi test nằm trong 1 transaction và rollback. */
class ReferralTest extends TestCase
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

    public function test_new_driver_gets_unique_referral_code(): void
    {
        $a = $this->makeUser('driver');
        $b = $this->makeUser('driver');

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $a->fresh()->referral_code);
        $this->assertNotSame($a->fresh()->referral_code, $b->fresh()->referral_code);
        // Shop cũng có mã (dùng cho giới thiệu shop → shop), duy nhất toàn bảng users.
        $shopCode = $this->makeUser('shop')->fresh()->referral_code;
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $shopCode);
        $this->assertNotSame($a->fresh()->referral_code, $shopCode);
    }

    public function test_find_driver_by_code_ignores_case_and_inactive_drivers(): void
    {
        $driver = $this->makeUser('driver');
        $code = $driver->fresh()->referral_code;

        $this->assertSame($driver->id, ReferralService::findDriverByCode(strtolower(' '.$code.' '))->id);
        $this->assertNull(ReferralService::findDriverByCode('ZZZZZZ'));

        $driver->update(['status' => 0]);
        $this->assertNull(ReferralService::findDriverByCode($code));
    }

    public function test_attach_creates_pending_referral_and_blocks_self_referral(): void
    {
        $driver = $this->makeUser('driver');
        $shop = $this->makeUser('shop');
        ReferralService::attachShop($shop, $driver->fresh()->referral_code);

        $this->assertSame($driver->id, (int) $shop->fresh()->referred_by_driver_id);
        $this->assertSame('pending', DriverReferral::where('shop_id', $shop->id)->value('status'));

        $selfShop = $this->makeUser('shop', $driver->phone);
        ReferralService::attachShop($selfShop, $driver->fresh()->referral_code);
        $this->assertNull($selfShop->fresh()->referred_by_driver_id);
        $this->assertSame(0, DriverReferral::where('shop_id', $selfShop->id)->count());
    }

    public function test_driver_is_rewarded_once_after_required_orders(): void
    {
        OperationalSettings::put(['referral.reward_amount' => 70000, 'referral.min_orders' => 2], $this->cityId());
        [$driver, $shop] = $this->referredShop();
        $other = $this->makeUser('driver');

        $first = $this->completedOrder($shop, $other);
        ReferralService::onOrderCompleted($first);
        $this->assertSame('pending', DriverReferral::where('shop_id', $shop->id)->value('status'));
        $this->assertSame(0.0, $this->balance($driver));

        $second = $this->completedOrder($shop, $other);
        ReferralService::onOrderCompleted($second);
        ReferralService::onOrderCompleted($second); // chạy lại không cộng trùng

        $referral = DriverReferral::where('shop_id', $shop->id)->first();
        $this->assertSame('rewarded', $referral->status);
        $this->assertSame(70000, (int) $referral->reward_amount);
        $this->assertSame(70000.0, $this->balance($driver));
        $this->assertSame(1, DB::table('driver_wallet_transactions')->where('reference', "referral_{$shop->id}")->count());
    }

    public function test_orders_delivered_by_the_referrer_do_not_count(): void
    {
        [$driver, $shop] = $this->referredShop();

        ReferralService::onOrderCompleted($this->completedOrder($shop, $driver));

        $this->assertSame('pending', DriverReferral::where('shop_id', $shop->id)->value('status'));
        $this->assertSame(0.0, $this->balance($driver));
    }

    public function test_zero_reward_marks_rewarded_without_wallet_credit(): void
    {
        OperationalSettings::put(['referral.reward_amount' => 0], $this->cityId());
        [$driver, $shop] = $this->referredShop();

        ReferralService::onOrderCompleted($this->completedOrder($shop, $this->makeUser('driver')));

        $this->assertSame('rewarded', DriverReferral::where('shop_id', $shop->id)->value('status'));
        $this->assertSame(0.0, $this->balance($driver));
    }

    public function test_admin_can_grant_pending_referral_once(): void
    {
        OperationalSettings::put(['referral.reward_amount' => 40000], $this->cityId());
        [$driver, $shop] = $this->referredShop();
        $referral = DriverReferral::where('shop_id', $shop->id)->first();

        $this->assertTrue(ReferralService::grantReward($referral, $shop->city_id));
        $this->assertFalse(ReferralService::grantReward($referral, $shop->city_id));

        $this->assertSame('rewarded', $referral->fresh()->status);
        $this->assertSame(40000.0, $this->balance($driver));
    }

    public function test_rejected_referral_is_never_rewarded(): void
    {
        [$driver, $shop] = $this->referredShop();
        $referral = DriverReferral::where('shop_id', $shop->id)->first();

        $this->assertTrue(ReferralService::reject($referral, 'Shop ảo'));
        $this->assertFalse(ReferralService::reject($referral, 'lần hai'));
        $this->assertFalse(ReferralService::grantReward($referral, $shop->city_id));
        ReferralService::onOrderCompleted($this->completedOrder($shop, $this->makeUser('driver')));

        $this->assertSame('rejected', $referral->fresh()->status);
        $this->assertSame('Shop ảo', $referral->fresh()->reject_reason);
        $this->assertSame(0.0, $this->balance($driver));
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
        $driver = $this->makeUser('driver');
        $shop = $this->makeUser('shop');
        ReferralService::attachShop($shop, $driver->fresh()->referral_code);

        return [$driver, $shop];
    }

    private function makeUser(string $type, ?string $phone = null): User
    {
        return User::create([
            'name' => "Referral {$type}",
            'email' => 'ref-'.uniqid().'@test.local',
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
            'code' => 'REF'.uniqid(),
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

    private function balance(User $driver): float
    {
        return (float) (DriverWallet::where('driver_id', $driver->id)->value('balance') ?? 0);
    }
}
