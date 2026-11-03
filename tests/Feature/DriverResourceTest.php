<?php

namespace Tests\Feature;

use App\Filament\Resources\DriverResource\Pages\EditDriver;
use App\Filament\Resources\DriverResource\Pages\ViewDriver;
use App\Filament\Resources\DriverResource\RelationManagers\DriverCccdImagesRelationManager;
use App\Models\DriverStatusLog;
use App\Services\DriverAccountService;
use App\Support\KycFile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverCccdImage;
use Modules\Order\Models\Order;
use Tests\TestCase;

class DriverResourceTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Storage::fake('local');
        Storage::fake('public');
        $this->city = City::create(['name' => 'Driver city', 'slug' => 'drv-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->driver = $this->user('driver');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, array $extra = []): User
    {
        return User::create($extra + [
            'name' => ucfirst($type).' drv', 'email' => 'drv-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function logs(string $action): \Illuminate\Support\Collection
    {
        return DriverStatusLog::where('driver_id', $this->driver->id)->where('action', $action)->get();
    }

    public function test_kyc_uploads_are_private_and_only_served_through_signed_urls(): void
    {
        Sanctum::actingAs($this->driver);
        $res = $this->postJson('/api/driver/profile/cccd-image', ['image' => UploadedFile::fake()->image('cccd.jpg')])->assertOk();
        $path = DriverCccdImage::where('user_id', $this->driver->id)->value('image_path');

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        $url = $res->json('image_url');
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk();
        $this->get(route('kyc.file', ['path' => $path]))->assertForbidden();
    }

    public function test_legacy_public_files_are_still_readable_and_get_moved(): void
    {
        $path = 'driver-cccd/'.str_repeat('a', 40).'.jpg';
        Storage::disk('public')->put($path, 'img');
        DriverCccdImage::create(['user_id' => $this->driver->id, 'image_path' => $path, 'status' => 'pending']);

        $this->get(KycFile::url($path))->assertOk();
        $this->assertNull(KycFile::url('../.env'));
        $this->assertNull(KycFile::url('banners/x.jpg'));

        $orphan = 'driver-licenses/'.str_repeat('b', 40).'.jpg';
        Storage::disk('public')->put($orphan, 'old');

        $this->artisan('kyc:make-private', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('public')->assertExists($path);

        $this->artisan('kyc:make-private')->assertSuccessful();
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        Storage::disk('local')->assertExists($orphan);
        Storage::disk('public')->assertMissing($orphan);
        $this->assertSame('old', Storage::disk('local')->get($orphan), 'ảnh cũ được chuyển, không bị xóa');
        $this->get(KycFile::url($path))->assertOk();
    }

    public function test_document_review_records_who_and_when(): void
    {
        $doc = DriverCccdImage::create(['user_id' => $this->driver->id, 'image_path' => 'driver-cccd/x.jpg', 'status' => 'pending']);

        Livewire::test(DriverCccdImagesRelationManager::class, ['ownerRecord' => $this->driver, 'pageClass' => ViewDriver::class])
            ->callTableAction('review', $doc, ['status' => 'rejected', 'rejection_reason' => 'Ảnh mờ'])
            ->assertHasNoTableActionErrors();

        $doc->refresh();
        $this->assertSame('rejected', $doc->status);
        $this->assertSame($this->admin->id, (int) $doc->reviewed_by);
        $this->assertNotNull($doc->reviewed_at);
    }

    public function test_changing_city_drops_old_shifts_and_is_logged(): void
    {
        $other = City::create(['name' => 'Khu mới', 'slug' => 'km-'.uniqid(), 'is_active' => true]);
        $old = Shift::create(['city_id' => $this->city->id, 'code' => 'o'.uniqid(), 'name' => 'Ca cũ', 'start_time' => '07:00', 'end_time' => '12:00', 'is_active' => true]);
        $old->users()->attach($this->driver->id);

        Livewire::test(EditDriver::class, ['record' => $this->driver->id])
            ->fillForm(['city_id' => $other->id])->call('save')->assertHasNoFormErrors();

        $this->driver->refresh();
        $this->assertSame($other->id, (int) $this->driver->city_id);
        $this->assertSame(0, $this->driver->registeredShifts()->count(), 'ca của khu vực cũ được gỡ');
        $log = $this->logs('city_change')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Ca cũ', $log->reason);
        $this->assertSame($this->admin->id, (int) $log->performed_by);
    }

    public function test_cleanup_command_removes_old_city_shifts(): void
    {
        $other = City::create(['name' => 'Khu khác', 'slug' => 'kk-'.uniqid(), 'is_active' => true]);
        $foreign = Shift::create(['city_id' => $other->id, 'code' => 'f'.uniqid(), 'name' => 'Ca khác', 'start_time' => '07:00', 'end_time' => '12:00', 'is_active' => true]);
        $mine = Shift::create(['city_id' => $this->city->id, 'code' => 'm'.uniqid(), 'name' => 'Ca mình', 'start_time' => '13:00', 'end_time' => '18:00', 'is_active' => true]);
        $foreign->users()->attach($this->driver->id);
        $mine->users()->attach($this->driver->id);

        $this->artisan('drivers:detach-foreign-shifts', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, $this->driver->registeredShifts()->count(), 'chạy thử không đổi gì');

        $this->artisan('drivers:detach-foreign-shifts')->assertSuccessful();
        $this->assertSame([$mine->id], $this->driver->registeredShifts()->pluck('shifts.id')->all());
        $this->assertCount(1, $this->logs('city_change'));
    }

    public function test_duplicate_phone_is_a_form_error_and_phone_or_debt_changes_are_logged(): void
    {
        $other = $this->user('driver');
        Livewire::test(EditDriver::class, ['record' => $this->driver->id])
            ->fillForm(['phone' => $other->phone])->call('save')->assertHasFormErrors(['phone' => 'unique']);

        // Cùng số với một khách hàng thì được (duy nhất theo từng loại tài khoản).
        $customer = $this->user('customer');
        Livewire::test(EditDriver::class, ['record' => $this->driver->id])
            ->fillForm(['phone' => $customer->phone, 'debt_enabled' => false, 'license_plate' => ' 68a1-123.45 '])
            ->call('save')->assertHasNoFormErrors();

        $this->driver->refresh();
        $this->assertSame('68A1-123.45', $this->driver->license_plate);
        $this->assertCount(1, $this->logs('phone_change'));
        $this->assertCount(1, $this->logs('debt_off'));
    }

    public function test_unlock_only_applies_to_locked_drivers(): void
    {
        $service = app(DriverAccountService::class);
        $pending = $this->user('driver', ['status' => 0]);

        $this->assertFalse($service->unlock($pending, null, $this->admin->id)['ok']);
        $this->assertSame(0, (int) $pending->fresh()->status, 'hồ sơ chờ duyệt không bị mở thành hoạt động');

        $pending->update(['status' => 2]);
        $this->assertTrue($service->unlock($pending, 'Đã nộp phạt', $this->admin->id)['ok']);
        $this->assertSame(1, (int) $pending->fresh()->status);
    }

    public function test_profile_earnings_use_the_shared_driver_earnings_formula(): void
    {
        foreach ([['shipping_fee' => 20000, 'bonus_fee' => 5000], ['shipping_fee' => 15000, 'discount_amount' => 10000]] as $money) {
            Order::create($money + [
                'code' => 'DR'.uniqid(), 'status' => 'completed', 'city_id' => $this->city->id, 'service_type' => 'delivery',
                'platform' => 'shop_app', 'payment_method' => 'cod', 'pickup_address' => 'a', 'delivery_address' => 'b',
                'bonus_fee' => 0, 'night_surcharge' => 0, 'discount_amount' => 0, 'delivery_man_id' => $this->driver->id, 'completed_at' => now()->subDay(),
            ]);
        }

        $summary = Livewire::test(ViewDriver::class, ['record' => $this->driver->id])->instance()->getSummary();
        $this->assertSame(50000, $summary['earnings']);
    }

    public function test_city_cannot_change_while_the_driver_is_online(): void
    {
        $other = City::create(['name' => 'Khu bận', 'slug' => 'kb-'.uniqid(), 'is_active' => true]);
        $this->driver->update(['is_online' => true]);

        Livewire::test(EditDriver::class, ['record' => $this->driver->id])
            ->fillForm(['city_id' => $other->id])->call('save')->assertHasErrors(['data.city_id']);

        $this->assertSame($this->city->id, (int) $this->driver->fresh()->city_id, 'luật chặn đổi khu vực trước đây không bao giờ chạy');
    }
}
