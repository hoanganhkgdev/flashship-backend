<?php

namespace Tests\Feature;

use App\Filament\Resources\DriverLeaveRequestResource\Pages\CreateDriverLeaveRequest;
use App\Filament\Resources\DriverLeaveRequestResource\Pages\ListDriverLeaveRequests;
use App\Filament\Resources\DriverLeaveRequestResource\Pages\ViewDriverLeaveRequest;
use App\Services\DriverLeaveService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverLeaveRequest;
use Modules\Order\Models\Order;
use Tests\TestCase;

class DriverLeaveTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Leave city', 'slug' => 'leave-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->driver = $this->user('driver');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, int $status = 1): User
    {
        return User::create([
            'name' => ucfirst($type).' leave', 'email' => 'lv-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => $status, 'city_id' => $this->city->id,
        ]);
    }

    public function test_multi_day_leave_creates_one_record_per_day_skips_existing_and_logs(): void
    {
        DriverLeaveRequest::create(['driver_id' => $this->driver->id, 'leave_date' => today()->addDay(), 'note' => 'có sẵn', 'created_by' => $this->admin->id]);

        $r = DriverLeaveService::create($this->driver->id, today(), today()->addDays(2), 'Đi thi', $this->admin->id);

        $this->assertTrue($r['ok']);
        $this->assertSame([2, 1], [$r['created'], $r['skipped']]);
        $this->assertSame(3, DriverLeaveRequest::where('driver_id', $this->driver->id)->count());
        $this->assertSame(2, DB::table('driver_leave_logs')->where('driver_id', $this->driver->id)->where('action', 'created')->count());
    }

    public function test_validation_rules(): void
    {
        $id = $this->driver->id;
        $this->assertFalse(DriverLeaveService::create($id, today(), null, 'ab', $this->admin->id)['ok'], 'lý do quá ngắn');
        $this->assertFalse(DriverLeaveService::create($id, today()->subDay(), null, 'Ốm nặng', $this->admin->id)['ok'], 'ngày đã qua');
        $this->assertFalse(DriverLeaveService::create($id, today(), today()->addDays(14), 'Về quê', $this->admin->id)['ok'], 'quá 14 ngày');
        $this->assertTrue(DriverLeaveService::create($id, today()->addDay(), today()->addDays(14), 'Về quê', $this->admin->id)['ok'], 'đúng 14 ngày');
        $this->assertFalse(DriverLeaveService::create($this->user('driver', 2)->id, today(), null, 'Ốm nặng', $this->admin->id)['ok'], 'tài xế đã khóa');
        $this->assertFalse(DriverLeaveService::create($id, today()->addDay(), null, 'Ốm nặng', $this->admin->id)['ok'], 'đã có phiếu ngày đó');
    }

    public function test_leave_today_is_blocked_while_driver_has_an_unfinished_order_but_future_is_fine(): void
    {
        Order::create(['code' => 'LV'.uniqid(), 'status' => 'assigned', 'delivery_man_id' => $this->driver->id, 'pickup_address' => 'a', 'delivery_address' => 'b', 'service_type' => 'delivery']);

        $this->assertFalse(DriverLeaveService::create($this->driver->id, today(), null, 'Ốm đột xuất', $this->admin->id)['ok']);
        $this->assertTrue(DriverLeaveService::create($this->driver->id, today()->addDay(), null, 'Nghỉ mai', $this->admin->id)['ok']);
    }

    public function test_leave_today_forces_driver_offline_and_closes_sessions(): void
    {
        DB::table('users')->where('id', $this->driver->id)->update(['is_online' => true, 'online_since' => now()]);
        DB::table('driver_shift_sessions')->insert(['driver_id' => $this->driver->id, 'started_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue(DriverLeaveService::create($this->driver->id, today(), null, 'Ốm nặng', $this->admin->id)['ok']);

        $this->assertSame(0, (int) DB::table('users')->where('id', $this->driver->id)->value('is_online'));
        $this->assertSame(0, DB::table('driver_shift_sessions')->where('driver_id', $this->driver->id)->whereNull('ended_at')->count());
    }

    public function test_only_today_and_future_leaves_can_be_cancelled_with_a_reason(): void
    {
        $future = DriverLeaveRequest::create(['driver_id' => $this->driver->id, 'leave_date' => today()->addDays(2), 'note' => 'x', 'created_by' => $this->admin->id]);
        $past = DriverLeaveRequest::create(['driver_id' => $this->driver->id, 'leave_date' => today()->subDays(3), 'note' => 'x', 'created_by' => $this->admin->id]);

        $this->assertFalse(DriverLeaveService::cancel($future, '', $this->admin->id)['ok']);
        $this->assertFalse(DriverLeaveService::cancel($past, 'Nhầm ngày', $this->admin->id)['ok']);
        $this->assertNotNull(DriverLeaveRequest::find($past->id), 'phiếu đã qua giữ nguyên');

        $this->assertTrue(DriverLeaveService::cancel($future, 'Tài xế đổi ý', $this->admin->id)['ok']);
        $this->assertNull(DriverLeaveRequest::find($future->id));
        $log = DB::table('driver_leave_logs')->where('action', 'cancelled')->first();
        $this->assertSame('Tài xế đổi ý', $log->note);
        $this->assertSame($this->admin->id, (int) $log->performed_by);
    }

    public function test_impact_subtracts_drivers_on_leave_per_shift(): void
    {
        $shift = Shift::create(['city_id' => $this->city->id, 'code' => 'm', 'name' => 'Ca sáng', 'start_time' => '07:00', 'end_time' => '13:00', 'is_active' => true]);
        $other = $this->user('driver');
        $shift->users()->attach([$this->driver->id, $other->id]);
        DriverLeaveRequest::create(['driver_id' => $this->driver->id, 'leave_date' => today()->addDay(), 'note' => 'x', 'created_by' => $this->admin->id]);

        $rows = DriverLeaveService::impact($this->city->id)['rows'];
        $this->assertSame([2, 0], [$rows[0]['cells'][0]['remaining'], $rows[0]['cells'][0]['away']]);
        $this->assertSame([1, 1], [$rows[1]['cells'][0]['remaining'], $rows[1]['cells'][0]['away']]);

        // Xem trước khi thêm người nghỉ nữa
        $preview = DriverLeaveService::impact($this->city->id, 7, [today()->addDay()->toDateString() => [$other->id]])['rows'];
        $this->assertSame(0, $preview[1]['cells'][0]['remaining']);
    }

    public function test_flags_for_frequent_leave_and_work_before_record(): void
    {
        foreach (range(1, 4) as $i) {
            DriverLeaveRequest::create(['driver_id' => $this->driver->id, 'leave_date' => today()->subDays($i), 'note' => 'x', 'created_by' => $this->admin->id]);
        }
        $leave = DriverLeaveRequest::create(['driver_id' => $this->driver->id, 'leave_date' => today(), 'note' => 'x', 'created_by' => $this->admin->id]);
        Order::create(['code' => 'LF'.uniqid(), 'status' => 'completed', 'delivery_man_id' => $this->driver->id, 'pickup_address' => 'a', 'delivery_address' => 'b', 'service_type' => 'delivery', 'completed_at' => today()->startOfDay()]); // đầu ngày: luôn trước lúc ghi nhận kể cả khi chạy test ngay sau 00:00

        $keys = array_column(DriverLeaveService::flags($leave->fresh()), 'key');
        $this->assertContains('frequent', $keys);
        $this->assertContains('worked', $keys);
        $this->assertContains('noshift', $keys);
    }

    public function test_pages_render_and_create_form_works_without_a_free_edit_page(): void
    {
        $leave = DriverLeaveRequest::create(['driver_id' => $this->driver->id, 'leave_date' => today()->addDay(), 'note' => 'Nghỉ', 'created_by' => $this->admin->id]);

        Livewire::test(ListDriverLeaveRequests::class)->assertSuccessful()->assertCanSeeTableRecords([$leave])
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete');
        Livewire::test(ViewDriverLeaveRequest::class, ['record' => $leave->id])->assertSuccessful()->assertSee('Lịch sử nghỉ 90 ngày');
        $this->assertFalse(\App\Filament\Resources\DriverLeaveRequestResource::hasPage('edit'));

        Livewire::test(CreateDriverLeaveRequest::class)
            ->fillForm(['driver_id' => $this->driver->id, 'from_date' => today()->addDays(3)->toDateString(), 'to_date' => today()->addDays(4)->toDateString(), 'note' => 'Đi thi'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(3, DriverLeaveRequest::where('driver_id', $this->driver->id)->count());

        Livewire::test(ListDriverLeaveRequests::class)
            ->callTableAction('cancel_leave', $leave, ['reason' => 'ab'])->assertHasTableActionErrors(['reason']);
        Livewire::test(ListDriverLeaveRequests::class)
            ->callTableAction('cancel_leave', $leave, ['reason' => 'Tài xế đổi ý'])->assertHasNoTableActionErrors();
        $this->assertNull(DriverLeaveRequest::find($leave->id));
    }
}
