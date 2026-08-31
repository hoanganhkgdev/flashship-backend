<?php

namespace Tests\Feature\Shop;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\VoucherService;
use Modules\Order\Services\OrderService;
use Modules\Shop\Http\Controllers\AuthController;
use Modules\Shop\Http\Controllers\OrderController;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class ShopOrderDataIntegrityTest extends TestCase
{
    private const TEST_CONNECTION = [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'flashship_backend_test',
        'username' => 'root',
        'password' => 'Nika221124@',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'strict' => true,
    ];

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql' => self::TEST_CONNECTION]);
        config(['database.default' => 'mysql']);
        DB::purge('mysql');

        $this->prefix = 'si-'.uniqid();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('voucher_usages')->whereIn('order_id', function ($query) {
            $query->select('id')->from('orders')->where('code', 'like', $this->prefix.'%');
        })->delete();
        DB::table('orders')->where('code', 'like', $this->prefix.'%')->delete();
        DB::table('vouchers')->where('code', 'like', strtoupper($this->prefix).'%')->delete();
        DB::table('users')->where('email', 'like', $this->prefix.'%')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        parent::tearDown();
    }

    public function test_voucher_increment_rolls_back_with_outer_order_transaction(): void
    {
        $user = $this->makeShop();
        $voucherId = DB::table('vouchers')->insertGetId([
            'code' => strtoupper($this->prefix).'-VOUCHER',
            'type' => 'fixed',
            'value' => 10_000,
            'audience' => 'shop',
            'used_count' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $controller = new OrderController(app(OrderService::class));
        $method = new ReflectionMethod($controller, 'tryApplyVoucher');

        try {
            DB::transaction(function () use ($controller, $method, $user): void {
                $method->invoke($controller, strtoupper($this->prefix).'-VOUCHER', $user, 50_000);
                throw new RuntimeException('Simulate order persistence failure');
            });
        } catch (RuntimeException) {
            // Expected: the complete order transaction is rolled back.
        }

        $this->assertSame(0, (int) DB::table('vouchers')->where('id', $voucherId)->value('used_count'));
    }

    public function test_voucher_preview_and_redeem_use_the_same_discount_base(): void
    {
        $user = $this->makeShop();
        $code = strtoupper($this->prefix).'-PERCENT';
        DB::table('vouchers')->insert([
            'code' => $code,
            'type' => 'percent',
            'value' => 20,
            'max_discount' => 15_000,
            'audience' => 'shop',
            'used_count' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(VoucherService::class);
        $preview = $service->preview($code, $user, 'shop', 'delivery', 100_000);
        $redeemed = DB::transaction(
            fn () => $service->redeem($code, $user, 'shop', 'delivery', 100_000)
        );

        $this->assertTrue($preview['valid']);
        $this->assertSame(15_000, $preview['discount']);
        $this->assertSame($preview['discount'], $redeemed['discount_amount']);
    }

    public function test_delete_shop_account_does_not_cancel_orders_from_other_platforms(): void
    {
        $user = $this->makeShop();
        $shopOrderId = $this->makeOrder($user->id, 'shop_app', '-SHOP');
        $customerOrderId = $this->makeOrder($user->id, 'customer_app', '-CUSTOMER');

        $request = Request::create('/api/shop/auth/account', 'DELETE');
        $request->setUserResolver(fn () => $user);

        (new AuthController)->deleteAccount($request);

        $this->assertSame('cancelled', DB::table('orders')->where('id', $shopOrderId)->value('status'));
        $this->assertSame('pending', DB::table('orders')->where('id', $customerOrderId)->value('status'));
    }

    private function makeShop(): User
    {
        return User::create([
            'name' => 'Shop Integrity Test',
            'email' => $this->prefix.'-'.uniqid().'@test.local',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'shop',
            'status' => 1,
        ]);
    }

    private function makeOrder(int $senderId, string $platform, string $suffix): int
    {
        return DB::table('orders')->insertGetId([
            'code' => $this->prefix.$suffix,
            'service_type' => 'delivery',
            'sender_platform_id' => $senderId,
            'platform' => $platform,
            'status' => 'pending',
            'shipping_fee' => 0,
            'bonus_fee' => 0,
            'is_freeship' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
