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
}
