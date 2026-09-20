<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\User;
use Modules\Core\Models\Voucher;
use Modules\Core\Services\VoucherService;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Tests\TestCase;

class VoucherHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->customer = User::create([
            'name' => 'Voucher hardening test',
            'email' => 'voucher-hardening-'.uniqid().'@test.local',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'customer',
            'status' => 1,
        ]);
    }

    public function test_first_order_voucher_rejects_customer_with_completed_order(): void
    {
        $voucher = $this->makeVoucher();
        $this->makeOrder('completed');

        $result = app(VoucherService::class)
            ->evaluate($voucher, $this->customer, 'customer', 'delivery', 50_000);

        $this->assertFalse($result['valid']);
        $this->assertSame('FIRST_ORDER_ONLY', $result['reason_code']);
    }

    public function test_first_order_voucher_cannot_be_used_by_two_pending_orders(): void
    {
        $voucher = $this->makeVoucher(perUserLimit: null);
        $orderId = $this->makeOrder('pending');
        DB::table('voucher_usages')->insert([
            'voucher_id' => $voucher->id,
            'user_id' => $this->customer->id,
            'order_id' => $orderId,
            'used_at' => now(),
        ]);

        $result = app(VoucherService::class)
            ->evaluate($voucher, $this->customer, 'customer', 'delivery', 50_000);

        $this->assertFalse($result['valid']);
        $this->assertSame('FIRST_ORDER_ONLY', $result['reason_code']);
    }

    public function test_first_order_voucher_rejects_existing_pending_order_even_from_another_voucher(): void
    {
        $voucher = $this->makeVoucher();
        $this->makeOrder('pending');

        $result = app(VoucherService::class)
            ->evaluate($voucher, $this->customer, 'customer', 'delivery', 50_000);

        $this->assertFalse($result['valid']);
        $this->assertSame('FIRST_ORDER_ONLY', $result['reason_code']);
    }

    public function test_admin_cancellation_restores_voucher_usage_once(): void
    {
        $voucher = $this->makeVoucher();
        $voucher->update(['used_count' => 1]);
        $orderId = $this->makeOrder('assigned');
        DB::table('voucher_usages')->insert([
            'voucher_id' => $voucher->id,
            'user_id' => $this->customer->id,
            'order_id' => $orderId,
            'used_at' => now(),
        ]);

        $result = app(OrderService::class)
            ->cancelAssignedOrderByAdmin(Order::findOrFail($orderId));

        $this->assertTrue($result['success']);
        $this->assertSame('cancelled', Order::findOrFail($orderId)->status);
        $this->assertDatabaseMissing('voucher_usages', ['order_id' => $orderId]);
        $this->assertSame(0, (int) $voucher->fresh()->used_count);
    }

    public function test_distance_limited_voucher_rejects_long_order(): void
    {
        $voucher = $this->makeVoucher(maxDistanceKm: 10);

        $result = app(VoucherService::class)
            ->evaluate($voucher, $this->customer, 'customer', 'delivery', 50_000, 10.01);

        $this->assertFalse($result['valid']);
        $this->assertSame('DISTANCE_NOT_SUPPORTED', $result['reason_code']);
    }

    public function test_distance_limited_voucher_accepts_order_at_limit(): void
    {
        $voucher = $this->makeVoucher(maxDistanceKm: 10);

        $result = app(VoucherService::class)
            ->evaluate($voucher, $this->customer, 'customer', 'delivery', 50_000, 10);

        $this->assertTrue($result['valid']);
    }

    public function test_order_cannot_complete_far_from_delivery_point(): void
    {
        $orderId = $this->makeOrder('processing');
        DB::table('orders')->where('id', $orderId)->update([
            'delivery_man_id' => $this->customer->id,
            'delivery_lat' => 10.0000000,
            'delivery_lng' => 105.0000000,
        ]);
        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')
            ->once()
            ->with([$this->customer->id])
            ->andReturn([$this->customer->id => ['lat' => 10.01, 'lng' => 105.01, 'bearing' => null]]);

        $result = app(OrderService::class)
            ->completeOrder(Order::findOrFail($orderId), $this->customer);

        $this->assertFalse($result['success']);
        $this->assertSame('TOO_FAR_FROM_DELIVERY', $result['reason_code']);
        $this->assertSame(422, $result['status']);
        $this->assertSame('processing', Order::findOrFail($orderId)->status);
    }

    public function test_completion_rejects_missing_fresh_driver_location(): void
    {
        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->once()->andReturn([]);

        $result = app(OrderService::class)->checkDriverProximity(
            $this->customer,
            10.0000000,
            105.0000000,
        );

        $this->assertSame('DRIVER_LOCATION_UNAVAILABLE', $result['reason_code']);
        $this->assertSame(422, $result['status']);
    }

    public function test_completion_allows_fresh_location_inside_300_metres(): void
    {
        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->once()->andReturn([
            $this->customer->id => ['lat' => 10.002, 'lng' => 105.0, 'bearing' => null],
        ]);

        $result = app(OrderService::class)->checkDriverProximity(
            $this->customer,
            10.0000000,
            105.0000000,
        );

        $this->assertNull($result);
    }

    public function test_arrived_order_is_auto_completed_after_three_minute_grace(): void
    {
        $orderId = $this->makeOrder('processing');
        DB::table('orders')->where('id', $orderId)->update([
            'delivery_man_id' => $this->customer->id,
            'delivery_lat' => 10.0000000,
            'delivery_lng' => 105.0000000,
            'delivery_arrived_at' => now()->subMinutes(4),
        ]);
        $this->mock(DriverLocationService::class)
            ->shouldReceive('freshLocationsFor')->once()->andReturn([]);
        $orderService = $this->mock(OrderService::class);
        $orderService->shouldReceive('completeOrder')
            ->once()
            ->withArgs(fn (Order $order, User $driver, bool $verified) =>
                $order->id === $orderId && $driver->id === $this->customer->id && $verified
            )
            ->andReturn(['success' => true, 'status' => 200]);

        $this->assertSame(0, Artisan::call('orders:auto-complete-arrived'));
        $this->assertNotNull(DB::table('orders')->where('id', $orderId)->value('auto_completed_at'));
    }

    private function makeVoucher(?int $perUserLimit = 1, ?float $maxDistanceKm = null): Voucher
    {
        return Voucher::create([
            'code' => 'VH-'.strtoupper(uniqid()),
            'type' => 'fixed',
            'value' => 10_000,
            'audience' => 'customer',
            'per_user_limit' => $perUserLimit,
            'first_order_only' => true,
            'max_distance_km' => $maxDistanceKm,
            'used_count' => 0,
            'is_active' => true,
        ]);
    }

    private function makeOrder(string $status): int
    {
        return DB::table('orders')->insertGetId([
            'code' => 'VH-ORDER-'.strtoupper(uniqid()),
            'service_type' => 'delivery',
            'sender_platform_id' => $this->customer->id,
            'platform' => 'customer_app',
            'status' => $status,
            'shipping_fee' => 50_000,
            'bonus_fee' => 0,
            'is_freeship' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
