<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverCccdImage;
use Tests\TestCase;

class DriverOnlineToggleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_driver_cannot_spam_online_offline_toggle(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());

        $city = City::create([
            'name' => 'Toggle city',
            'slug' => 'toggle-city-'.uniqid(),
            'is_active' => true,
        ]);
        $driver = User::create([
            'name' => 'Toggle driver',
            'email' => 'toggle-'.uniqid().'@test.local',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'driver',
            'status' => 1,
            'city_id' => $city->id,
            'is_online' => false,
        ]);
        DriverCccdImage::create([
            'user_id' => $driver->id,
            'image_path' => 'tests/cccd.jpg',
            'status' => DriverCccdImage::STATUS_APPROVED,
        ]);
        $driver->registeredShifts()->attach(\Modules\Core\Models\Shift::create([
            'city_id' => $city->id, 'code' => 'c'.uniqid(), 'name' => 'Ca ngày',
            'start_time' => '06:00', 'end_time' => '22:00', 'is_active' => true,
        ])->id);

        $limitKey = "driver-online-toggle:{$driver->id}";
        RateLimiter::clear($limitKey);
        $this->beforeApplicationDestroyed(
            fn () => RateLimiter::clear($limitKey),
        );
        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/toggle-status', ['is_online' => true])
            ->assertOk()
            ->assertJsonPath('is_online', true);

        // Offline là thao tác an toàn nên luôn được thực hiện ngay.
        $this->postJson('/api/driver/toggle-status', ['is_online' => false])
            ->assertOk()
            ->assertJsonPath('is_online', false);

        $this->postJson('/api/driver/toggle-status', ['is_online' => true])
            ->assertStatus(429)
            ->assertJsonPath('code', 'status_toggle_cooldown')
            ->assertJsonPath('retry_after', fn ($seconds) => $seconds >= 1);

        // Retry cùng trạng thái vẫn idempotent và không bị chặn.
        $this->postJson('/api/driver/toggle-status', ['is_online' => false])
            ->assertOk()
            ->assertJsonPath('is_online', false);
    }

    public function test_driver_without_active_shift_cannot_go_online(): void
    {
        $city = City::create(['name' => 'No shift', 'slug' => 'no-shift-'.uniqid(), 'is_active' => true]);
        $driver = User::create([
            'name' => 'No shift', 'email' => 'noshift-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'city_id' => $city->id, 'is_online' => false,
        ]);
        DriverCccdImage::create(['user_id' => $driver->id, 'image_path' => 'tests/cccd.jpg', 'status' => DriverCccdImage::STATUS_APPROVED]);
        RateLimiter::clear("driver-online-toggle:{$driver->id}");
        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/toggle-status', ['is_online' => true])
            ->assertStatus(403)->assertJsonPath('code', 'no_shift');
        $this->assertFalse((bool) $driver->fresh()->is_online);
    }

    public function test_nearby_drivers_hides_id_and_exact_position(): void
    {
        $city = City::create(['name' => 'Nearby', 'slug' => 'nearby-'.uniqid(), 'is_active' => true]);
        $driver = User::create([
            'name' => 'Free', 'email' => 'free-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'city_id' => $city->id, 'is_online' => true,
        ]);
        $customer = User::create([
            'name' => 'Khách', 'email' => 'c-'.uniqid().'@test.local', 'phone' => '08'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'customer', 'status' => 1, 'city_id' => $city->id,
        ]);
        $this->mock(\Modules\Driver\Services\DriverLocationService::class)
            ->shouldReceive('freshLocationsFor')->andReturn([$driver->id => ['lat' => 10.0123456, 'lng' => 105.0987654, 'bearing' => 37.0, 'age_seconds' => 3]]);
        Sanctum::actingAs($customer);

        $d = $this->getJson('/api/customer/drivers/nearby?lat=10.01&lng=105.1')->assertOk()->json('data.0');
        $this->assertSame(1, $d['id']);
        $this->assertSame(10.012, $d['lat']);
        $this->assertSame(105.099, $d['lng']);
        $this->assertSame(45, $d['bearing']);
    }
}
