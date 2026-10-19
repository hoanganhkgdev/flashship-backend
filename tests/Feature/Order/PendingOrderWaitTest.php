<?php

namespace Tests\Feature\Order;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

/** Đơn chưa ai nhận chỉ hiện thời gian đã chờ, không có cảnh báo hay mốc hết hạn. */
class PendingOrderWaitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql' => self::mysqlTestConnection(), 'database.default' => 'mysql']);
        DB::purge('mysql');
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_pending_order_shows_only_how_long_it_has_waited(): void
    {
        $city = $this->city();
        $summary = new \ReflectionMethod(\App\Filament\Resources\OrderResource::class, 'statusSummary');

        $order = $this->pendingOrder($city); // tạo cách đây 20 phút
        $html = $summary->invoke(null, $order);

        $this->assertStringContainsString('Chờ 20 phút', $html);
        $this->assertStringNotContainsString('Không có tài xế', $html);
        $this->assertStringNotContainsString('is-alert', $html);
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
