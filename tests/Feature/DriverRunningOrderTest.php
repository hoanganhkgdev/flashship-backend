<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

/** Đơn đang chạy phía tài xế: đổi trạng thái, giao điểm đơn gộp, freeship, thu nhập. */
class DriverRunningOrderTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Running', 'slug' => 'running-'.uniqid(), 'is_active' => true]);
        $this->driver = User::create([
            'name' => 'Driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true,
            'city_id' => $this->city->id,
        ]);
    }

    private function order(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'code' => 'RUN-'.uniqid(), 'status' => 'assigned', 'city_id' => $this->city->id,
            'service_type' => 'delivery', 'platform' => 'call_center', 'delivery_man_id' => $this->driver->id,
            'pickup_address' => 'Điểm lấy', 'delivery_address' => 'Điểm giao', 'shipping_fee' => 20000,
        ], $attrs));
    }

    private function api(string $method, string $uri)
    {
        return $this->actingAs($this->driver, 'sanctum')->json($method, '/api'.$uri, $method === 'POST' && str_contains($uri, 'update-status') ? ['status' => 'processing'] : []);
    }

    public function test_pickup_does_not_overwrite_cancelled_order(): void
    {
        $order = $this->order();
        // Admin huỷ sau khi request của tài xế đã nạp đơn (route binding) — mô phỏng bằng cập nhật thẳng DB.
        DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled']);

        $this->api('POST', "/orders/{$order->id}/update-status")
            ->assertStatus(409)->assertJsonPath('code', 'cancelled');
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_repeated_pickup_is_success(): void
    {
        $order = $this->order();
        $this->api('POST', "/orders/{$order->id}/update-status")->assertOk();
        $this->api('POST', "/orders/{$order->id}/update-status")->assertOk()->assertJsonPath('success', true);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(1, \Modules\Order\Models\OrderHistory::where('order_id', $order->id)->where('type', 'picked_up')->count());
    }

    public function test_batch_final_stop_retry_completes_order(): void
    {
        $now = now()->toIso8601String();
        $order = $this->order([
            'status' => 'processing', 'is_batch' => true,
            'stops' => [['seq' => 1, 'delivered_at' => $now], ['seq' => 2, 'delivered_at' => $now]],
        ]);

        // Mọi điểm đã lưu "đã giao" nhưng đơn vẫn processing (lần hoàn thành trước lỗi): bấm lại phải hoàn thành.
        $this->api('POST', "/orders/{$order->id}/stops/2/deliver")
            ->assertOk()->assertJsonPath('completed', true);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_active_orders_flag_platform_freeship_and_skip_history(): void
    {
        $this->order(['is_freeship' => true]);
        $this->order(['is_freeship' => true, 'voucher_code' => 'GIAM', 'discount_amount' => 5000]);

        $res = $this->api('GET', '/orders/my-orders?active_only=1')->assertOk();
        $flags = collect($res->json('data.assigned'))->pluck('platform_freeship')->sort()->values()->all();
        $this->assertSame([false, true], $flags);
        $this->assertArrayNotHasKey('completed', $res->json('data'));

        // Bản app cũ không gửi active_only vẫn nhận lịch sử.
        $this->api('GET', '/orders/my-orders')->assertOk()->assertJsonStructure(['data' => ['assigned', 'completed']]);
    }

    public function test_earnings_summary_matches_wallet_credit(): void
    {
        // Khách trả 15.000 (đã trừ voucher 5.000) + công ty bù 5.000 + thưởng 2.000 = 22.000.
        $this->order([
            'status' => 'completed', 'completed_at' => now(), 'shipping_fee' => 15000,
            'voucher_code' => 'GIAM', 'discount_amount' => 5000, 'bonus_fee' => 2000,
        ]);
        // Freeship tổng đài: khách trả 0, công ty trả 20.000.
        $this->order(['status' => 'completed', 'completed_at' => now(), 'is_freeship' => true]);

        $this->api('GET', '/earnings/summary')->assertOk()
            ->assertJsonPath('data.today.total', 42000)
            ->assertJsonPath('data.today.orders', 2);
    }
}
