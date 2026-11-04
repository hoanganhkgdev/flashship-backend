<?php

namespace Tests\Feature;

use App\Services\DriverLeaveService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\City;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;
use Modules\Driver\Services\ShiftOfflineService;
use Tests\TestCase;

/** API ca làm/nghỉ phép phía tài xế: ca theo khu vực, yêu cầu đổi ca, giới hạn xin nghỉ. */
class DriverShiftApiTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Shift api', 'slug' => 'shift-api-'.uniqid(), 'is_active' => true]);
        $this->driver = User::create([
            'name' => 'Driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'secret-123', 'user_type' => 'driver', 'status' => 1, 'city_id' => $this->city->id,
        ]);
        Sanctum::actingAs($this->driver);
    }

    private function shift(int $cityId, string $start = '00:00', string $end = '23:59'): Shift
    {
        return Shift::create(['city_id' => $cityId, 'code' => 'c'.uniqid(), 'name' => 'Ca '.$start, 'start_time' => $start, 'end_time' => $end, 'is_active' => true]);
    }

    public function test_shift_of_previous_city_does_not_count(): void
    {
        $other = City::create(['name' => 'Cũ', 'slug' => 'old-'.uniqid(), 'is_active' => true]);
        $this->driver->registeredShifts()->attach($this->shift($other->id)->id);

        $this->assertFalse(ShiftService::activeRegistered($this->driver)->exists());
        $this->getJson('/api/driver/shifts')->assertOk()->assertJsonPath('current_shift_ids', [])
            ->assertJsonPath('current_shift_offline', null);
        $this->assertNull(app(ShiftOfflineService::class)->status($this->driver));

        // Được tự chọn ca khu vực mới (không bị báo "đã đăng ký rồi").
        $this->postJson('/api/driver/shifts/select', ['shift_ids' => [$this->shift($this->city->id)->id]])->assertOk();
    }

    public function test_change_request_identical_to_current_is_rejected(): void
    {
        $a = $this->shift($this->city->id, '06:00', '12:00');
        $this->driver->registeredShifts()->attach($a->id);

        $this->postJson('/api/driver/shifts/change-request', ['shift_ids' => [$a->id]])->assertStatus(422);
        $this->assertDatabaseMissing('driver_shift_change_requests', ['driver_id' => $this->driver->id]);
    }

    public function test_overlapping_shifts_rejected_via_shared_helper(): void
    {
        $night = $this->shift($this->city->id, '22:00', '06:00');
        $morning = $this->shift($this->city->id, '05:00', '10:00');

        $this->postJson('/api/driver/shifts/select', ['shift_ids' => [$night->id, $morning->id]])
            ->assertStatus(422)->assertJsonFragment(['success' => false]);
    }

    public function test_change_request_status_hides_internal_fields(): void
    {
        $a = $this->shift($this->city->id, '06:00', '12:00');
        $b = $this->shift($this->city->id, '13:00', '18:00');
        $this->driver->registeredShifts()->attach($a->id);
        $this->postJson('/api/driver/shifts/change-request', ['shift_ids' => [$b->id]])->assertOk();

        $data = $this->getJson('/api/driver/shifts/change-request/status')->assertOk()->json('data');
        $this->assertSame('pending', $data['status']);
        $this->assertSame([$b->id], array_map('intval', $data['shift_ids']));
        $this->assertArrayNotHasKey('processed_by', $data);
        $this->assertArrayNotHasKey('driver_id', $data);
    }

    public function test_leave_too_far_ahead_is_rejected(): void
    {
        $from = today()->addDays(DriverLeaveService::MAX_AHEAD_DAYS + 1)->toDateString();
        $this->postJson('/api/driver/shifts/leaves', ['from_date' => $from, 'note' => 'Về quê'])
            ->assertStatus(422);
    }
}
