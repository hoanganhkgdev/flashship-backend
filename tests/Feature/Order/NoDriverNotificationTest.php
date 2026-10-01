<?php

namespace Tests\Feature\Order;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Modules\Order\Services\DispatchService;
use Tests\TestCase;

/** Đơn quá thời gian chờ mà không có tài xế phải báo đúng người trong panel. */
class NoDriverNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql' => self::mysqlTestConnection(), 'database.default' => 'mysql']);
        DB::purge('mysql');
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        Redis::shouldReceive('del')->andReturn(1); // Redis không chạy ở local/CI
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_notifies_city_staff_and_global_admins_but_not_other_cities(): void
    {
        $city = $this->city();
        $other = $this->city();

        $callCenter = $this->staff('call_center', $city);
        $manager = $this->staff('city_manager', $city);
        $cityAdmin = $this->staff('admin', $city);
        $globalAdmin = $this->staff('admin', null);
        $otherCityCallCenter = $this->staff('call_center', $other);
        $otherCityAdmin = $this->staff('admin', $other);
        $globalCallCenter = $this->staff('call_center', null);

        $order = $this->pendingOrder($city);
        app(DispatchService::class)->markNoDriver($order);

        $notified = DB::table('notifications')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();
        sort($notified);
        $expected = [$callCenter->id, $manager->id, $cityAdmin->id, $globalAdmin->id];
        sort($expected);

        $this->assertSame($expected, $notified);
        $this->assertNotContains($otherCityCallCenter->id, $notified);
        $this->assertNotContains($otherCityAdmin->id, $notified);
        $this->assertNotContains($globalCallCenter->id, $notified);

        $title = json_decode(DB::table('notifications')->first()->data, true)['title'];
        $this->assertStringContainsString("Đơn #{$order->code}", $title);
    }

    public function test_order_stays_pending_and_is_flagged_for_manual_handling(): void
    {
        $city = $this->city();
        $order = $this->pendingOrder($city);

        app(DispatchService::class)->markNoDriver($order);

        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('no_driver', $fresh->cancel_reason);
    }

    public function test_no_notification_when_the_order_is_no_longer_pending(): void
    {
        $city = $this->city();
        $this->staff('call_center', $city);
        $order = $this->pendingOrder($city, 'assigned');

        app(DispatchService::class)->markNoDriver($order);

        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_writes_one_history_line_and_never_duplicates_it(): void
    {
        $city = $this->city();
        $this->staff('call_center', $city);
        $order = $this->pendingOrder($city);
        $service = app(DispatchService::class);

        $service->markNoDriver($order);
        $service->markNoDriver($order->fresh()); // retry job + vòng quét cùng chạm mốc

        $lines = DB::table('order_histories')->where('order_id', $order->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame('no_driver', $lines->first()->type);
        $this->assertStringContainsString('chưa có tài xế', $lines->first()->description);
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_list_status_shows_no_driver_only_once_the_timeout_is_reached(): void
    {
        $city = $this->city();
        $summary = new \ReflectionMethod(\App\Filament\Resources\OrderResource::class, 'statusSummary');

        $waiting = $this->pendingOrder($city);
        $waiting->forceFill(['created_at' => now()->subMinutes(3)]);
        $html = $summary->invoke(null, $waiting);
        $this->assertStringContainsString('Chờ 3 phút', $html);
        $this->assertStringNotContainsString('Không có tài xế', $html);

        $overdue = $this->pendingOrder($city); // tạo cách đây 20 phút
        $html = $summary->invoke(null, $overdue);
        $this->assertStringContainsString('Không có tài xế', $html);
        $this->assertStringContainsString('is-alert', $html);
    }

    private function city(): int
    {
        return DB::table('cities')->insertGetId([
            'name' => 'City '.uniqid(), 'slug' => 'c-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(string $type, ?int $cityId): User
    {
        return User::create([
            'name' => "Staff {$type}",
            'email' => 'nd-'.uniqid().'@test.local',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => $type,
            'status' => 1,
            'city_id' => $cityId,
        ]);
    }

    private function pendingOrder(int $cityId, string $status = 'pending'): Order
    {
        $id = DB::table('orders')->insertGetId([
            'code' => 'ND'.uniqid(),
            'service_type' => 'delivery',
            'platform' => 'call_center',
            'status' => $status,
            'city_id' => $cityId,
            'pickup_address' => '1 Test',
            'shipping_fee' => 0,
            'bonus_fee' => 0,
            'is_freeship' => false,
            'dispatch_started_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(20),
            'updated_at' => now(),
        ]);

        return Order::findOrFail($id);
    }
}
