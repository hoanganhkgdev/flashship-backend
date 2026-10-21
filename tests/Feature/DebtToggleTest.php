<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Models\DriverDebt;
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
}
