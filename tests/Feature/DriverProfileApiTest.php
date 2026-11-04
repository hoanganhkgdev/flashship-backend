<?php

namespace Tests\Feature;

use App\Models\DriverStatusLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverCccdImage;
use Tests\TestCase;

/** Hồ sơ/giấy tờ phía tài xế: đổi tên, tải lại CCCD, yêu cầu xoá tài khoản. */
class DriverProfileApiTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Profile', 'slug' => 'profile-'.uniqid(), 'is_active' => true]);
        $this->driver = User::create([
            'name' => 'Nguyen Van A', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'secret-123', 'user_type' => 'driver', 'status' => 1, 'city_id' => $this->city->id,
        ]);
        Sanctum::actingAs($this->driver);
    }

    public function test_name_change_is_logged_once_and_blocked_after_kyc_approved(): void
    {
        $this->postJson('/api/driver/profile/update', ['name' => 'Nguyen Van B'])->assertOk();
        $this->assertSame('Nguyen Van A → Nguyen Van B', DriverStatusLog::where('driver_id', $this->driver->id)->where('action', 'name_change')->value('reason'));

        $other = User::create([
            'name' => 'Tran C', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'x', 'user_type' => 'driver', 'status' => 1, 'city_id' => $this->city->id,
        ]);
        DriverCccdImage::create(['user_id' => $other->id, 'image_path' => 'x.jpg', 'status' => DriverCccdImage::STATUS_APPROVED]);
        Sanctum::actingAs($other);

        $this->getJson('/api/driver/profile')->assertOk()->assertJsonPath('data.user.name_locked', true);
        $this->postJson('/api/driver/profile/update', ['name' => 'Ten Khac'])
            ->assertStatus(403)->assertJsonPath('code', 'name_locked_kyc');
        $this->assertSame('Tran C', $other->fresh()->name);
    }

    public function test_reupload_after_rejection_clears_previous_review(): void
    {
        Storage::fake('local');
        $doc = DriverCccdImage::create(['user_id' => $this->driver->id, 'image_path' => 'old.jpg', 'status' => DriverCccdImage::STATUS_REJECTED, 'rejection_reason' => 'Mờ', 'reviewed_by' => $this->driver->id, 'reviewed_at' => now()]);

        $this->post('/api/driver/profile/cccd-image', ['image' => UploadedFile::fake()->image('cccd.jpg')], ['Accept' => 'application/json'])->assertOk();

        $doc->refresh();
        $this->assertSame(DriverCccdImage::STATUS_PENDING, $doc->status);
        $this->assertNull($doc->reviewed_by);
        $this->assertNull($doc->reviewed_at);
    }

    public function test_delete_request_notifies_admins_and_is_logged(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => uniqid().'@test.local', 'phone' => '07'.random_int(10000000, 99999999),
            'password' => 'x', 'user_type' => 'admin', 'status' => 1,
        ]);

        $this->postJson('/api/driver/delete-account/request')->assertOk();

        $this->assertNotNull($this->driver->fresh()->delete_requested_at);
        $this->assertTrue(DriverStatusLog::where('driver_id', $this->driver->id)->where('action', 'delete_req')->exists());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $admin->id)->count());

        $this->postJson('/api/driver/delete-account/cancel')->assertOk();
        $this->assertTrue(DriverStatusLog::where('driver_id', $this->driver->id)->where('action', 'delete_undo')->exists());
    }

    public function test_legacy_update_location_writes_nothing(): void
    {
        $before = DB::table('driver_location_logs')->count();
        $this->postJson('/api/driver/update-location', ['latitude' => 1, 'longitude' => 2])->assertOk();
        $this->assertSame($before, DB::table('driver_location_logs')->count());
    }
}
