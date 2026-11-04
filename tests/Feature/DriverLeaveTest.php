<?php

namespace Tests\Feature;

use App\Filament\Resources\DriverLeaveRequestResource\Pages\CreateDriverLeaveRequest;
use App\Filament\Resources\DriverLeaveRequestResource\Pages\ListDriverLeaveRequests;
use App\Filament\Resources\DriverLeaveRequestResource\Pages\ViewDriverLeaveRequest;
use App\Services\DriverLeaveService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
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

    public function test_driver_can_create_list_and_cancel_own_leave_from_api(): void
    {
        Sanctum::actingAs($this->driver);

        $this->postJson('/api/driver/shifts/leaves', [
            'from_date' => today()->addDay()->toDateString(),
            'to_date' => today()->addDays(2)->toDateString(),
            'note' => 'Có việc gia đình',
        ])->assertCreated()->assertJsonPath('data.created', 2);

        $this->assertSame(2, DriverLeaveRequest::where('driver_id', $this->driver->id)->count());
        $this->assertSame($this->driver->id, (int) DriverLeaveRequest::where('driver_id', $this->driver->id)->first()->created_by);

        $response = $this->getJson('/api/driver/shifts/leaves')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $leaveId = $response->json('data.0.id');
        $this->postJson("/api/driver/shifts/leaves/{$leaveId}/cancel")
            ->assertOk();
        $this->assertNull(DriverLeaveRequest::find($leaveId));
    }

    public function test_driver_cannot_cancel_another_drivers_leave(): void
    {
        $other = $this->user('driver');
        $leave = DriverLeaveRequest::create([
            'driver_id' => $other->id,
            'leave_date' => today()->addDay(),
            'note' => 'Có việc riêng',
            'created_by' => $other->id,
        ]);
        Sanctum::actingAs($this->driver);

        $this->postJson("/api/driver/shifts/leaves/{$leave->id}/cancel")
            ->assertNotFound();
        $this->assertNotNull($leave->fresh());
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

    private function driverRequestsLeave(\Carbon\Carbon $from, ?\Carbon\Carbon $to = null): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->driver);

        return $this->postJson('/api/driver/shifts/leaves', [
            'from_date' => $from->toDateString(),
            'to_date' => ($to ?? $from)->toDateString(),
            'note' => 'Có việc gia đình',
        ]);
    }

    public function test_driver_request_waits_for_approval_and_has_no_effect_until_approved(): void
    {
        DB::table('users')->where('id', $this->driver->id)->update(['is_online' => true, 'online_since' => now()]);

        $this->driverRequestsLeave(today())->assertCreated()
            ->assertJsonPath('message', 'Đã gửi yêu cầu nghỉ 1 ngày, vui lòng chờ admin duyệt.');

        $leave = DriverLeaveRequest::where('driver_id', $this->driver->id)->sole();
        $this->assertSame(DriverLeaveRequest::PENDING, $leave->status);
        $this->assertFalse(DriverLeaveService::isOnLeave($this->driver->id, today()), 'chờ duyệt chưa phải là nghỉ');
        $this->assertSame(1, (int) DB::table('users')->where('id', $this->driver->id)->value('is_online'), 'không bị tắt online');
        $this->assertSame(1, DB::table('driver_leave_logs')->where('driver_id', $this->driver->id)->where('action', 'requested')->count());
        $this->assertSame(0, DriverLeaveService::impact($this->city->id)['rows'][0]['onLeave'], 'chưa trừ khỏi ca');

        // Bật online vẫn không bị chặn bởi lý do nghỉ phép (chỉ còn các điều kiện khác như CCCD).
        DB::table('users')->where('id', $this->driver->id)->update(['is_online' => false]);
        $res = $this->postJson('/api/driver/toggle-status', ['is_online' => true]);
        $this->assertStringNotContainsString('nghỉ phép', (string) $res->json('message'));

        $this->getJson('/api/driver/shifts/leaves')->assertOk()
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.reject_reason', null);
    }

    public function test_admin_approval_makes_leave_effective_and_forces_offline_today(): void
    {
        $this->driverRequestsLeave(today())->assertCreated();
        $leave = DriverLeaveRequest::where('driver_id', $this->driver->id)->sole();
        DB::table('users')->where('id', $this->driver->id)->update(['is_online' => true, 'online_since' => now()]);

        $res = DriverLeaveService::approve($leave, $this->admin->id);

        $this->assertTrue($res['ok'], $res['message']);
        $leave->refresh();
        $this->assertSame(DriverLeaveRequest::APPROVED, $leave->status);
        $this->assertSame($this->admin->id, (int) $leave->reviewed_by);
        $this->assertNotNull($leave->reviewed_at);
        $this->assertTrue(DriverLeaveService::isOnLeave($this->driver->id, today()));
        $this->assertSame(0, (int) DB::table('users')->where('id', $this->driver->id)->value('is_online'));
        $this->assertFalse(DriverLeaveService::approve($leave, $this->admin->id)['ok'], 'không duyệt lại lần 2');

        Sanctum::actingAs($this->driver);
        $this->postJson('/api/driver/toggle-status', ['is_online' => true])
            ->assertForbidden()->assertJsonPath('message', 'Bạn đang được duyệt nghỉ phép hôm nay nên không thể bật online.');
    }

    public function test_rejected_request_keeps_driver_working_and_can_be_requested_again(): void
    {
        $this->driverRequestsLeave(today()->addDay())->assertCreated();
        $leave = DriverLeaveRequest::where('driver_id', $this->driver->id)->sole();

        $this->assertFalse(DriverLeaveService::reject($leave, 'ab', $this->admin->id)['ok'], 'lý do quá ngắn');
        $this->assertTrue(DriverLeaveService::reject($leave, 'Thiếu người chạy ca', $this->admin->id)['ok']);

        $leave->refresh();
        $this->assertSame(DriverLeaveRequest::REJECTED, $leave->status);
        $this->assertSame('Thiếu người chạy ca', $leave->reject_reason);
        $this->assertFalse(DriverLeaveService::isOnLeave($this->driver->id, today()->addDay()));
        $this->assertFalse(DriverLeaveService::approve($leave, $this->admin->id)['ok'], 'đã từ chối thì không duyệt');
        $this->assertFalse(DriverLeaveService::cancel($leave, 'Không cần nữa', $this->driver->id)['ok']);

        $this->getJson('/api/driver/shifts/leaves')->assertOk()
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.reject_reason', 'Thiếu người chạy ca');

        // Xin lại cùng ngày dùng lại bản ghi cũ, quay về chờ duyệt.
        $this->driverRequestsLeave(today()->addDay())->assertCreated()->assertJsonPath('data.created', 1);
        $leave->refresh();
        $this->assertSame(DriverLeaveRequest::PENDING, $leave->status);
        $this->assertNull($leave->reject_reason);
        $this->assertSame(1, DriverLeaveRequest::where('driver_id', $this->driver->id)->count());
    }

    public function test_approval_guards_past_dates_and_unfinished_orders(): void
    {
        $past = DriverLeaveRequest::create(['driver_id' => $this->driver->id, 'leave_date' => today()->subDay(), 'note' => 'x', 'status' => DriverLeaveRequest::PENDING, 'created_by' => $this->driver->id]);
        $this->assertFalse(DriverLeaveService::approve($past, $this->admin->id)['ok']);
        $this->assertSame('Quá hạn chưa duyệt', $past->statusLabel());

        $this->driverRequestsLeave(today())->assertCreated();
        $today = DriverLeaveRequest::where('driver_id', $this->driver->id)->whereDate('leave_date', today())->sole();
        Order::create(['code' => 'LA'.uniqid(), 'status' => 'processing', 'delivery_man_id' => $this->driver->id, 'pickup_address' => 'a', 'delivery_address' => 'b', 'service_type' => 'delivery']);

        $this->assertFalse(DriverLeaveService::approve($today, $this->admin->id)['ok']);
        $this->assertSame(DriverLeaveRequest::PENDING, $today->fresh()->status);
    }

    public function test_driver_can_withdraw_a_pending_request(): void
    {
        $this->driverRequestsLeave(today()->addDays(2))->assertCreated();
        $leave = DriverLeaveRequest::where('driver_id', $this->driver->id)->sole();

        $this->postJson("/api/driver/shifts/leaves/{$leave->id}/cancel")->assertOk()
            ->assertJsonPath('message', 'Đã rút yêu cầu nghỉ.');
        $this->assertNull(DriverLeaveRequest::find($leave->id));
    }

    public function test_admin_can_approve_and_reject_from_the_list_including_bulk(): void
    {
        $this->driverRequestsLeave(today()->addDay(), today()->addDays(3))->assertCreated();
        [$a, $b, $c] = DriverLeaveRequest::where('driver_id', $this->driver->id)->orderBy('leave_date')->get()->all();
        $this->actingAs($this->admin);

        Livewire::test(ListDriverLeaveRequests::class)->assertSuccessful()
            ->assertSet('activeTab', 'pending')
            ->assertCanSeeTableRecords([$a, $b, $c])
            ->callTableAction('approve_leave', $a)
            ->callTableAction('reject_leave', $b, ['reason' => 'Ca đó thiếu người'])->assertHasNoTableActionErrors();
        $this->assertSame([DriverLeaveRequest::APPROVED, DriverLeaveRequest::REJECTED], [$a->fresh()->status, $b->fresh()->status]);

        Livewire::test(ListDriverLeaveRequests::class)
            ->callTableBulkAction('approve_selected', [$b, $c]);
        $this->assertSame(DriverLeaveRequest::REJECTED, $b->fresh()->status, 'bulk duyệt bỏ qua phiếu đã từ chối');
        $this->assertSame(DriverLeaveRequest::APPROVED, $c->fresh()->status);

        $this->assertNull(\App\Filament\Resources\DriverLeaveRequestResource::getNavigationBadge(), 'hết phiếu chờ duyệt');
        Livewire::test(ViewDriverLeaveRequest::class, ['record' => $b->id])->assertSuccessful()
            ->assertSee('Từ chối')->assertSee('Ca đó thiếu người');
    }

    private function shiftFor(User $driver, string $start, string $end): Shift
    {
        $shift = Shift::create(['city_id' => $this->city->id, 'code' => 'lv'.uniqid(), 'name' => 'Ca '.$start, 'start_time' => $start, 'end_time' => $end, 'is_active' => true]);
        $shift->users()->attach($driver->id);
        DB::table('shift_user')->where('shift_id', $shift->id)->where('user_id', $driver->id)->update(['created_at' => now()->subDays(5)]);

        return $shift;
    }

    public function test_cancelling_todays_approved_leave_mid_shift_does_not_penalize_backwards(): void
    {
        $this->travelTo(today()->setTime(6, 0));
        $this->shiftFor($this->driver, '07:00', '13:00');
        DriverLeaveService::create($this->driver->id, today(), null, 'Bị ốm', $this->admin->id);
        $leave = DriverLeaveRequest::where('driver_id', $this->driver->id)->sole();
        $service = app(\Modules\Driver\Services\ShiftOfflineService::class);

        $this->travelTo(today()->setTime(10, 0));
        $this->assertSame(0, $service->check(), 'đang nghỉ: không phạt');
        $this->assertTrue(DriverLeaveService::cancel($leave, 'Khỏe lại, đi làm', $this->admin->id)['ok']);

        $this->travelTo(today()->setTime(10, 20));
        $this->assertSame(0, $service->check(), 'trước đây bị coi là vắng từ 07:00 và bị phạt ngay');
        $this->travelTo(today()->setTime(10, 31));
        $this->assertSame(1, $service->check(), 'vắng quá 30 phút kể từ lúc hủy thì vẫn phạt');
    }

    public function test_withdrawing_a_pending_request_mid_shift_does_not_forgive_earlier_absence(): void
    {
        // Yêu cầu chưa duyệt thì tài xế chưa từng được miễn: rút yêu cầu không được xóa khoảng đã vắng.
        $this->travelTo(today()->setTime(6, 0));
        $this->shiftFor($this->driver, '07:00', '13:00');
        DriverLeaveService::create($this->driver->id, today(), null, 'Xin nghỉ', $this->driver->id, needsApproval: true);
        $leave = DriverLeaveRequest::where('driver_id', $this->driver->id)->sole();

        $this->travelTo(today()->setTime(9, 0));
        $this->assertTrue(DriverLeaveService::cancel($leave, 'Tài xế tự hủy trên ứng dụng', $this->driver->id)['ok']);
        $this->assertSame('withdrawn', DB::table('driver_leave_logs')->where('driver_id', $this->driver->id)->latest('id')->value('action'));
        $this->assertSame(1, app(\Modules\Driver\Services\ShiftOfflineService::class)->check());
    }

    public function test_admin_recording_a_requested_day_approves_it(): void
    {
        DriverLeaveService::create($this->driver->id, today()->addDay(), today()->addDays(2), 'Về quê', $this->driver->id, needsApproval: true);
        $this->assertSame(2, DriverLeaveRequest::where('driver_id', $this->driver->id)->where('status', 'pending')->count());

        $r = DriverLeaveService::create($this->driver->id, today()->addDay(), today()->addDays(3), 'Về quê (đã gọi xác nhận)', $this->admin->id);
        $this->assertTrue($r['ok']);
        $this->assertSame([3, 0], [$r['created'], $r['skipped']], '2 ngày đang chờ được duyệt luôn + 1 ngày mới');
        $this->assertSame(3, DriverLeaveRequest::where('driver_id', $this->driver->id)->where('status', 'approved')->count());
        $this->assertSame($this->admin->id, (int) DriverLeaveRequest::where('driver_id', $this->driver->id)->whereDate('leave_date', today()->addDay())->value('reviewed_by'));
    }

    public function test_summary_is_scoped_and_counts_expired_requests(): void
    {
        DriverLeaveRequest::create(['driver_id' => $this->driver->id, 'leave_date' => today()->subDays(2), 'note' => 'cũ', 'status' => 'pending', 'created_by' => $this->driver->id]);
        DriverLeaveService::create($this->driver->id, today(), null, 'Bị ốm', $this->admin->id);

        $s = DriverLeaveService::summary($this->city->id);
        $this->assertSame(1, $s['today']);
        $this->assertSame(1, $s['expired']);
        $this->assertSame(0, DriverLeaveService::summary(null)['today']);

        Livewire::test(ListDriverLeaveRequests::class)->assertSuccessful()->assertSee('đã qua ngày mà chưa được duyệt');
    }
}
