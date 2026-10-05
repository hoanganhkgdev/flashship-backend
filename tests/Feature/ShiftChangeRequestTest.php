<?php

namespace Tests\Feature;

use App\Filament\Resources\DriverShiftChangeRequestResource\Pages\ListDriverShiftChangeRequests;
use App\Filament\Resources\DriverShiftChangeRequestResource\Pages\ViewDriverShiftChangeRequest;
use App\Services\ShiftChangeService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverShiftChangeRequest;
use Tests\TestCase;

class ShiftChangeRequestTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    private Shift $morning;

    private Shift $afternoon;

    private Shift $evening;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Change city', 'slug' => 'change-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->morning = $this->shift('07:00', '13:00');
        $this->afternoon = $this->shift('13:00', '18:00');
        $this->evening = $this->shift('18:00', '00:00');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
        Carbon::setTestNow(Carbon::today()->setTime(3, 0)); // 03:00: không ca nào đang chạy
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $type, int $status = 1): User
    {
        return User::create([
            'name' => ucfirst($type).' change', 'email' => 'sc-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => $status, 'city_id' => $this->city->id,
        ]);
    }

    private function shift(string $start, string $end): Shift
    {
        return Shift::create(['city_id' => $this->city->id, 'code' => 'c'.uniqid(), 'name' => 'Ca '.$start, 'start_time' => $start, 'end_time' => $end, 'is_active' => true]);
    }

    private function request(array $shifts, ?User $driver = null, array $current = []): DriverShiftChangeRequest
    {
        $driver ??= $this->user('driver');
        if ($current) {
            $driver->registeredShifts()->sync(array_map(fn ($s) => $s->id, $current));
        }

        return DriverShiftChangeRequest::create(['driver_id' => $driver->id, 'shift_ids' => array_map(fn ($s) => $s->id, $shifts), 'status' => 'pending'])->load('driver.registeredShifts');
    }

    public function test_approval_swaps_the_shifts_and_stores_who_approved(): void
    {
        $r = $this->request([$this->evening], null, [$this->morning]);

        $res = ShiftChangeService::approve($r, $this->admin->id);

        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame([$this->evening->id], $r->driver->registeredShifts()->pluck('shifts.id')->all());
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame($this->admin->id, (int) $r->fresh()->processed_by);
        $this->assertFalse(ShiftChangeService::approve($r->fresh(), $this->admin->id)['ok'], 'không duyệt lần hai');
    }

    public function test_cannot_approve_while_old_or_new_shift_is_running_and_reports_when_it_can(): void
    {
        $r = $this->request([$this->evening], null, [$this->morning]);

        Carbon::setTestNow(Carbon::today()->setTime(9, 0)); // ca sáng (ca cũ) đang chạy
        $can = ShiftChangeService::canApproveNow($r);
        $this->assertFalse($can['ok']);
        $this->assertStringContainsString('13:00', $can['reason']);
        $this->assertFalse(ShiftChangeService::approve($r, $this->admin->id)['ok']);
        $this->assertSame('pending', $r->fresh()->status);
    }

    public function test_locked_driver_requests_cannot_be_approved_and_are_flagged(): void
    {
        $r = $this->request([$this->evening], $this->user('driver', 2));

        $this->assertFalse(ShiftChangeService::canApproveNow($r)['ok']);
        $this->assertFalse(ShiftChangeService::approve($r, $this->admin->id)['ok']);
        $this->assertContains('locked', array_column(ShiftChangeService::flags($r), 'key'));
    }

    public function test_impact_shows_before_and_after_per_shift(): void
    {
        $r = $this->request([$this->afternoon], null, [$this->morning]);
        $other = $this->user('driver');
        $this->morning->users()->attach($other->id);

        $impact = ShiftChangeService::impact($r)->keyBy(fn ($i) => $i['shift']->id);
        $this->assertSame([2, 1], [$impact[$this->morning->id]['before'], $impact[$this->morning->id]['after']]);
        $this->assertSame([0, 1], [$impact[$this->afternoon->id]['before'], $impact[$this->afternoon->id]['after']]);
    }

    public function test_reject_requires_a_reason_and_stores_it(): void
    {
        $r = $this->request([$this->evening]);

        $this->assertFalse(ShiftChangeService::reject($r, 'other', '.', 1)['ok']);
        $this->assertFalse(ShiftChangeService::reject($r, 'bad', null, 1)['ok']);

        $this->assertTrue(ShiftChangeService::reject($r, 'no_capacity', null, $this->admin->id)['ok']);
        $r->refresh();
        $this->assertSame('rejected', $r->status);
        $this->assertSame('no_capacity', $r->reject_reason);
        $this->assertStringContainsString('không còn đủ chỗ', $r->admin_note);
        $this->assertFalse(ShiftChangeService::reject($r, 'no_capacity', null, 1)['ok']);
    }

    public function test_frequent_and_stale_flags(): void
    {
        $driver = $this->user('driver');
        foreach (range(1, 3) as $i) {
            $this->request([$this->evening], $driver);
        }
        $r = DriverShiftChangeRequest::where('driver_id', $driver->id)->first();
        $r->forceFill(['created_at' => now()->subHours(30)])->save();

        $keys = array_column(ShiftChangeService::flags($r->load('driver.registeredShifts')), 'key');
        $this->assertContains('frequent', $keys);
        $this->assertContains('stale', $keys);
    }

    public function test_api_blocks_locked_drivers_from_requesting_a_change(): void
    {
        $locked = $this->user('driver', 2);
        Sanctum::actingAs($locked);

        $this->postJson('/api/driver/shifts/change-request', ['shift_ids' => [$this->evening->id]])->assertStatus(403);
        $this->assertSame(0, DriverShiftChangeRequest::where('driver_id', $locked->id)->count());
    }

    public function test_pages_render_and_table_actions_work(): void
    {
        $r = $this->request([$this->evening], null, [$this->morning]);

        Livewire::test(ListDriverShiftChangeRequests::class)->assertSuccessful()->assertCanSeeTableRecords([$r]);
        Livewire::test(ViewDriverShiftChangeRequest::class, ['record' => $r->id])->assertSuccessful()->assertSee('Tác động lên từng ca');

        Livewire::test(ListDriverShiftChangeRequests::class)
            ->callTableAction('reject', $r, ['reason' => 'other', 'note' => ''])->assertHasTableActionErrors(['note']);
        Livewire::test(ListDriverShiftChangeRequests::class)
            ->callTableAction('approve', $r)->assertHasNoTableActionErrors();
        $this->assertSame('approved', $r->fresh()->status);
    }
}
