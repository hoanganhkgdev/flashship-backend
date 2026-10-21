<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Models\DriverDebt;
use Modules\Order\Models\Order;
use Tests\TestCase;

class DebtToggleTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Toggle city', 'slug' => 'toggle-city-'.uniqid(), 'is_active' => true, 'weekly_fee' => 100000]);
    }

    private function driver(bool $debtEnabled = true): User
    {
        return User::create([
            'name' => 'Driver toggle', 'email' => 'dt-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'city_id' => $this->city->id,
            'debt_enabled' => $debtEnabled,
        ]);
    }

    public function test_debt_applies_only_when_city_and_driver_both_enabled(): void
    {
        $driver = $this->driver();
        $this->assertTrue($driver->debtApplies());

        $driver->update(['debt_enabled' => false]);
        $this->assertFalse($driver->fresh()->debtApplies());

        $driver->update(['debt_enabled' => true]);
        OperationalSettings::put(['debt.enabled' => 0], $this->city->id);
        $this->assertFalse($driver->fresh()->debtApplies());
    }

    public function test_weekly_fees_skip_drivers_with_debt_turned_off(): void
    {
        $on = $this->driver();
        $off = $this->driver(false);

        Artisan::call('driver:generate-weekly-fees');

        $this->assertTrue(DriverDebt::where('driver_id', $on->id)->exists());
        $this->assertFalse(DriverDebt::where('driver_id', $off->id)->exists());
    }

    public function test_weekly_fees_skip_city_with_debt_turned_off(): void
    {
        $driver = $this->driver();
        OperationalSettings::put(['debt.enabled' => 0], $this->city->id);

        Artisan::call('driver:generate-weekly-fees');

        $this->assertFalse(DriverDebt::where('driver_id', $driver->id)->exists());
    }

    private function completedOrder(User $driver, int $fee, ?\Carbon\Carbon $at = null): void
    {
        $order = Order::create([
            'status' => 'completed', 'city_id' => $this->city->id, 'service_type' => 'delivery', 'platform' => 'customer_app',
            'pickup_address' => 'A', 'delivery_address' => 'B', 'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
            'delivery_man_id' => $driver->id, 'shipping_fee' => $fee,
        ]);
        $order->forceFill(['completed_at' => $at ?? now()->subDay()->setTime(12, 0)])->save();
    }

    public function test_daily_percent_collects_share_of_yesterdays_shipping_fees(): void
    {
        OperationalSettings::put(['debt.collection_mode' => 'daily_percent', 'debt.daily_percent' => 10], $this->city->id);
        $driver = $this->driver();
        $this->completedOrder($driver, 20000);
        $this->completedOrder($driver, 30000);
        $this->completedOrder($driver, 99000, now()->setTime(9, 0)); // hôm nay: không tính

        Artisan::call('driver:generate-daily-commission');
        Artisan::call('driver:generate-daily-commission'); // chạy lại không tạo trùng

        $debts = DriverDebt::where('driver_id', $driver->id)->get();
        $this->assertCount(1, $debts);
        $this->assertSame('commission', $debts[0]->debt_type);
        $this->assertEquals(5000, $debts[0]->amount_due);
    }

    public function test_daily_percent_skips_weekly_cities_and_weekly_skips_daily_cities(): void
    {
        $driver = $this->driver();
        $this->completedOrder($driver, 20000);

        Artisan::call('driver:generate-daily-commission');
        $this->assertFalse(DriverDebt::where('driver_id', $driver->id)->where('debt_type', 'commission')->exists());

        OperationalSettings::put(['debt.collection_mode' => 'daily_percent', 'debt.daily_percent' => 10], $this->city->id);
        Artisan::call('driver:generate-weekly-fees');
        $this->assertFalse(DriverDebt::where('driver_id', $driver->id)->where('debt_type', 'weekly')->exists());
    }

    public function test_daily_percent_skips_drivers_with_debt_turned_off(): void
    {
        OperationalSettings::put(['debt.collection_mode' => 'daily_percent', 'debt.daily_percent' => 10], $this->city->id);
        $off = $this->driver(false);
        $this->completedOrder($off, 20000);

        Artisan::call('driver:generate-daily-commission');

        $this->assertFalse(DriverDebt::where('driver_id', $off->id)->exists());
    }

    public function test_daily_commission_turns_overdue_after_configured_hours(): void
    {
        OperationalSettings::put(['debt.collection_mode' => 'daily_percent', 'debt.daily_percent' => 10, 'debt.daily_overdue_hours' => 12], $this->city->id);
        $driver = $this->driver();
        $this->completedOrder($driver, 20000);
        Artisan::call('driver:generate-daily-commission');
        $debt = DriverDebt::where('driver_id', $driver->id)->firstOrFail();

        Artisan::call('driver:mark-overdue-debts');
        $this->assertSame('pending', $debt->fresh()->status);

        DriverDebt::whereKey($debt->id)->update(['created_at' => now()->subHours(13)]);
        Artisan::call('driver:mark-overdue-debts');
        $this->assertSame('overdue', $debt->fresh()->status);
    }
}
