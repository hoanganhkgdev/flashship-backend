<?php

namespace Tests\Feature;

use App\Models\DriverStatusLog;
use App\Services\DriverAccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

/** Bỏ các tác động ra ngoài (Firebase, Redis, phát đơn) để chỉ kiểm thử luật nghiệp vụ. */
class FakeDriverAccountService extends DriverAccountService
{
    public int $locked = 0;

    public int $unlocked = 0;

    protected function afterLock(User $driver, $offers): void
    {
        $this->locked++;
    }

    protected function afterUnlock(User $driver): void
    {
        $this->unlocked++;
    }
}

class DriverAccountServiceTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'TP tài xế', 'slug' => 'drv-'.uniqid()]);
    }

    private function driver(int $status = 0): User
    {
        return User::create([
            'name' => 'Tài xế thử', 'email' => 'drv-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => $status, 'city_id' => $this->city->id,
        ]);
    }

    private function doc(string $table, User $driver, string $status): void
    {
        DB::table($table)->insert(['user_id' => $driver->id, 'image_path' => 'x.png', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_can_approve_without_cccd_but_warns(): void
    {
        $service = new FakeDriverAccountService;
        $driver = $this->driver();

        $result = $service->approve($driver);
        $this->assertTrue($result['ok']);
        $this->assertContains('CCCD chưa được tải lên', $result['warnings']);
        $this->assertSame(1, (int) $driver->fresh()->status);
    }

    public function test_approves_with_cccd_but_warns_when_license_missing(): void
    {
        $service = new FakeDriverAccountService;
        $driver = $this->driver();
        $this->doc('driver_cccd_images', $driver, 'approved');

        $result = $service->approve($driver, 7);

        $this->assertTrue($result['ok']);
        $this->assertSame(['Bằng lái chưa được tải lên'], $result['warnings']);
        $this->assertSame(1, (int) $driver->fresh()->status);
        $log = DriverStatusLog::where('driver_id', $driver->id)->first();
        $this->assertSame('approved', $log->action);
        $this->assertSame(7, $log->performed_by);
    }

    public function test_latest_document_decides_not_older_ones(): void
    {
        $driver = $this->driver();
        $this->doc('driver_cccd_images', $driver, 'approved');
        $this->doc('driver_cccd_images', $driver, 'rejected'); // tải lại và bị từ chối => không còn được duyệt

        $check = (new FakeDriverAccountService)->approvalCheck($driver);
        $this->assertTrue($check['ok']);
        $this->assertStringContainsString('bị từ chối', $check['warnings'][0]);
    }

    public function test_approve_only_works_on_pending_accounts(): void
    {
        $driver = $this->driver(2);
        $this->doc('driver_cccd_images', $driver, 'approved');

        $result = (new FakeDriverAccountService)->approve($driver);

        $this->assertFalse($result['ok']);
        $this->assertSame(2, (int) $driver->fresh()->status, 'tài xế bị khóa không được duyệt lại ngầm');
    }

    public function test_lock_records_reason_and_unlock_records_history(): void
    {
        $service = new FakeDriverAccountService;
        $driver = $this->driver(1);

        $this->assertTrue($service->lock($driver, 'Nợ phí tuần quá hạn', 3)['ok']);
        $driver->refresh();
        $this->assertSame(2, (int) $driver->status);
        $this->assertFalse((bool) $driver->is_online);
        $this->assertSame(1, $service->locked);

        $this->assertTrue($service->unlock($driver, 'Đã nộp đủ', 3)['ok']);
        $this->assertSame(1, (int) $driver->fresh()->status);
        $this->assertSame(1, $service->unlocked);

        $logs = DriverStatusLog::where('driver_id', $driver->id)->orderBy('id')->get();
        $this->assertSame(['locked', 'unlocked'], $logs->pluck('action')->all());
        $this->assertSame('Nợ phí tuần quá hạn', $logs[0]->reason);
        $this->assertSame('Đã nộp đủ', $logs[1]->reason);
    }

    public function test_cannot_lock_driver_holding_an_order(): void
    {
        $service = new FakeDriverAccountService;
        $driver = $this->driver(1);
        $order = Order::create([
            'code' => 'T'.random_int(100000, 999999), 'service_type' => 'delivery', 'city_id' => $this->city->id,
            'delivery_man_id' => $driver->id, 'status' => 'assigned', 'platform' => 'call_center', 'shipping_fee' => 10000,
            'pickup_address' => 'A', 'delivery_address' => 'B', 'payment_method' => 'cod',
        ]);

        $result = $service->lock($driver, 'Thử');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString($order->code, $result['message']);
        $this->assertSame(1, (int) $driver->fresh()->status);
        $this->assertSame(0, DriverStatusLog::where('driver_id', $driver->id)->count());
        $this->assertSame(0, $service->locked);
    }

    public function test_has_history_blocks_delete_for_drivers_with_orders(): void
    {
        $service = new FakeDriverAccountService;
        $fresh = $this->driver(1);
        $this->assertFalse($service->hasHistory($fresh));

        $worked = $this->driver(2);
        Order::create([
            'code' => 'H'.random_int(100000, 999999), 'service_type' => 'delivery', 'city_id' => $this->city->id,
            'delivery_man_id' => $worked->id, 'status' => 'completed', 'platform' => 'call_center', 'shipping_fee' => 10000,
            'pickup_address' => 'A', 'delivery_address' => 'B', 'payment_method' => 'cod',
        ]);
        $this->assertTrue($service->hasHistory($worked));
    }
}
