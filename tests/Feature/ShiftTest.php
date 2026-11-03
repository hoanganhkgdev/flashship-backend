<?php

namespace Tests\Feature;

use App\Filament\Resources\ShiftResource;
use App\Filament\Resources\ShiftResource\Pages\CreateShift;
use App\Filament\Resources\ShiftResource\Pages\EditShift;
use App\Filament\Resources\ShiftResource\Pages\ListShifts;
use App\Filament\Resources\ShiftResource\Pages\ViewShift;
use App\Services\ShiftService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Shift city', 'slug' => 'shift-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, int $status = 1): User
    {
        return User::create([
            'name' => ucfirst($type).' shift', 'email' => 'sh-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => $status, 'city_id' => $this->city->id,
        ]);
    }

    private function shift(string $start, string $end, bool $active = true, ?string $code = null): Shift
    {
        return Shift::create(['city_id' => $this->city->id, 'code' => $code ?? 'c'.uniqid(), 'name' => 'Ca '.$start, 'start_time' => $start, 'end_time' => $end, 'is_active' => $active]);
    }

    public function test_overlap_detection_handles_overnight_and_touching_shifts(): void
    {
        $this->assertTrue(ShiftService::overlaps('07:00', '13:00', '12:00', '18:00'));
        $this->assertFalse(ShiftService::overlaps('07:00', '13:00', '13:00', '18:00'), 'giáp nhau không tính trùng');
        $this->assertTrue(ShiftService::overlaps('18:00', '00:00', '23:00', '02:00'));
        $this->assertTrue(ShiftService::overlaps('22:00', '06:00', '05:00', '07:00'));
        $this->assertFalse(ShiftService::overlaps('22:00', '06:00', '06:00', '22:00'));
    }

    public function test_overlapping_shifts_are_allowed_but_zero_length_is_not(): void
    {
        $this->shift('07:00', '13:00');

        Livewire::test(CreateShift::class)
            ->fillForm(['name' => 'Ca chồng giờ', 'start_time' => '12:00', 'end_time' => '18:00', 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();

        Livewire::test(CreateShift::class)
            ->fillForm(['name' => 'Ca rỗng', 'start_time' => '09:00', 'end_time' => '09:00', 'is_active' => true])
            ->call('create')->assertHasFormErrors(['end_time']);
    }

    public function test_the_morning_noon_evening_schedule_can_be_configured(): void
    {
        foreach ([
            ['Ca sáng', '07:00', '16:00'],
            ['Ca trưa', '10:00', '18:00'],
            ['Ca tối', '18:00', '01:30'],
        ] as [$name, $start, $end]) {
            Livewire::test(CreateShift::class)
                ->fillForm(['name' => $name, 'start_time' => $start, 'end_time' => $end, 'is_active' => true])
                ->call('create')->assertHasNoFormErrors();
        }
        $this->assertSame(3, Shift::where('city_id', $this->city->id)->where('is_active', true)->count());

        $evening = Shift::where('name', 'Ca tối')->first();
        Carbon::setTestNow(Carbon::today()->setTime(0, 45));
        $this->assertTrue($evening->isNowInShift(), '00:45 vẫn thuộc ca tối kết thúc 01:30');
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        $this->assertFalse($evening->isNowInShift());
        Carbon::setTestNow(Carbon::today()->setTime(12, 0));
        $this->assertTrue(Shift::where('name', 'Ca sáng')->first()->isNowInShift());
        $this->assertTrue(Shift::where('name', 'Ca trưa')->first()->isNowInShift(), 'ca sáng và ca trưa chồng giờ nhau');
        Carbon::setTestNow();

        // Ca không còn lỗ hổng 02:00–07:00 chỉ còn khoảng 01:30–07:00.
        $gaps = ShiftService::coverage($this->city->id)['gaps'];
        $this->assertSame([['from' => '01:30', 'to' => '07:00']], $gaps->map(fn ($g) => ['from' => $g['from'], 'to' => $g['to']])->all());
    }

    public function test_toggle_logs_and_reactivation_is_allowed_even_when_hours_overlap(): void
    {
        $a = $this->shift('07:00', '13:00');
        $b = $this->shift('12:00', '18:00', false);

        $this->assertNull(ShiftService::activate($b, $this->admin->id));
        $this->assertTrue($b->fresh()->is_active);

        $d = $this->user('driver');
        $a->users()->attach($d->id);
        $this->assertSame(1, ShiftService::deactivate($a, $this->admin->id));
        $this->assertFalse($a->fresh()->is_active);
        $this->assertSame('deactivated', $a->logs()->first()->action);
    }

    public function test_time_can_be_changed_even_while_the_shift_is_running(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(10, 0));
        $s = $this->shift('07:00', '13:00');

        Livewire::test(EditShift::class, ['record' => $s->id])
            ->fillForm(['start_time' => '08:00', 'end_time' => '13:00'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('08:00', substr($s->fresh()->start_time, 0, 5));
        $this->assertStringContainsString('07:00–13:00 → 08:00–13:00', $s->logs()->where('action', 'time_changed')->first()->detail);
        Carbon::setTestNow();
    }

    public function test_coverage_counts_only_active_drivers_and_finds_gaps(): void
    {
        $s = $this->shift('07:00', '19:00');
        $active = $this->user('driver');
        $locked = $this->user('driver', 2);
        $s->users()->attach([$active->id, $locked->id]);

        $row = ShiftService::coverage($this->city->id)['rows']->first();
        $this->assertSame(1, $row['active']);
        $this->assertSame(1, $row['locked']);

        $gaps = ShiftService::coverage($this->city->id)['gaps'];
        $this->assertSame([['from' => '00:00', 'to' => '07:00'], ['from' => '19:00', 'to' => '00:00']], $gaps->map(fn ($g) => ['from' => $g['from'], 'to' => $g['to']])->all());
    }

    public function test_pages_render(): void
    {
        $s = $this->shift('07:00', '13:00');
        $s->users()->attach($this->user('driver')->id);

        Livewire::test(ListShifts::class)->assertSuccessful()->assertCanSeeTableRecords([$s]);
        Livewire::test(ViewShift::class, ['record' => $s->id])->assertSuccessful()->assertSee('Tài xế đăng ký ca');
    }

    private function driverIn(Shift ...$shifts): User
    {
        $d = $this->user('driver');
        foreach ($shifts as $s) {
            $s->users()->attach($d->id);
            DB::table('shift_user')->where('shift_id', $s->id)->where('user_id', $d->id)->update(['created_at' => now()->subDays(5)]);
        }

        return $d;
    }

    public function test_enabling_or_changing_a_running_shift_does_not_penalize_backwards(): void
    {
        $s = $this->shift('07:00', '16:00', false);
        $d = $this->driverIn($s);
        $service = app(\Modules\Driver\Services\ShiftOfflineService::class);

        // 11:00: admin bật ca. Trước đây tài xế bị tính vắng từ 07:00.
        Carbon::setTestNow(Carbon::today()->setTime(11, 0));
        ShiftService::activate($s, $this->admin->id);
        $s->refresh();
        Carbon::setTestNow(Carbon::today()->setTime(11, 20));
        $this->assertSame(0, $service->check(), 'mới vắng 20 phút kể từ lúc bật ca');
        Carbon::setTestNow(Carbon::today()->setTime(11, 31));
        $this->assertSame(1, $service->check(), 'vắng quá 30 phút kể từ lúc bật ca thì vẫn bị phạt');

        // Đổi giờ giữa ca cũng đặt lại mốc.
        $d2 = $this->driverIn($s2 = $this->shift('10:00', '20:00'));
        Carbon::setTestNow(Carbon::today()->setTime(14, 0));
        $s2->update(['start_time' => '08:00']);
        ShiftService::log($s2->id, 'time_changed', $this->admin->id, '10:00–20:00 → 08:00–20:00');
        Carbon::setTestNow(Carbon::today()->setTime(14, 10));
        $this->assertSame(0, $service->check());
        $status = $service->status($d2);
        $this->assertLessThanOrEqual(600, $status['offline_seconds']);
        Carbon::setTestNow();
    }

    public function test_inactive_shifts_cannot_be_registered_through_the_api(): void
    {
        $off = $this->shift('07:00', '13:00', false);
        $on = $this->shift('13:00', '18:00');
        $d = $this->user('driver');
        \Laravel\Sanctum\Sanctum::actingAs($d);

        $this->postJson('/api/driver/shifts/select', ['shift_ids' => [$off->id]])->assertStatus(422);
        $this->assertSame(0, $d->registeredShifts()->count());
        $this->postJson('/api/driver/shifts/select', ['shift_ids' => [$on->id]])->assertOk();
    }

    public function test_a_driver_left_with_only_a_switched_off_shift_can_pick_again(): void
    {
        $off = $this->shift('07:00', '13:00');
        $on = $this->shift('13:00', '18:00');
        $d = $this->driverIn($off);
        ShiftService::deactivate($off, $this->admin->id);
        \Laravel\Sanctum\Sanctum::actingAs($d);

        $this->getJson('/api/driver/shifts')->assertOk()->assertJsonPath('current_shift_ids', []);
        $this->postJson('/api/driver/shifts/select', ['shift_ids' => [$on->id]])->assertOk();
        $this->assertSame([$on->id], $d->registeredShifts()->pluck('shifts.id')->all(), 'ca đã tắt được thay bằng ca mới');
    }

    public function test_drivers_without_an_active_shift_are_counted_and_listed(): void
    {
        $a = $this->shift('07:00', '13:00');
        $b = $this->shift('13:00', '18:00');
        $ok = $this->driverIn($b);
        $stranded = $this->driverIn($a);
        $never = $this->user('driver');
        ShiftService::deactivate($a, $this->admin->id);

        $s = ShiftService::summary($this->city->id);
        $this->assertSame(3, $s['activeDrivers']);
        $this->assertSame(1, $s['noShift']);
        $this->assertSame(1, $s['idle']);

        $list = ShiftService::driversWithoutActiveShift($this->city->id);
        $this->assertEqualsCanonicalizing([$stranded->id, $never->id], $list->pluck('id')->all());
        $this->assertNotContains($ok->id, $list->pluck('id')->all());
    }

    public function test_changing_hours_or_enabling_is_blocked_when_a_registered_driver_would_overlap(): void
    {
        $morning = $this->shift('07:00', '13:00');
        $afternoon = $this->shift('13:00', '18:00');
        $d = $this->driverIn($morning, $afternoon);

        Livewire::test(EditShift::class, ['record' => $afternoon->id])
            ->fillForm(['start_time' => '12:00', 'end_time' => '18:00'])->call('save');
        $this->assertSame('13:00', substr($afternoon->fresh()->start_time, 0, 5), 'đổi giờ bị chặn vì tài xế sẽ trùng ca sáng');

        // Ca tắt rồi đổi giờ trùng thì được; bật lại mới bị chặn.
        $afternoon->update(['is_active' => false]);
        $afternoon->update(['start_time' => '12:00']);
        $this->assertNotNull(ShiftService::activate($afternoon->fresh(), $this->admin->id));
        $this->assertFalse($afternoon->fresh()->is_active);

        Livewire::test(EditShift::class, ['record' => $afternoon->id])
            ->fillForm(['start_time' => '13:00', 'end_time' => '18:00', 'is_active' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($afternoon->fresh()->is_active);
        $this->assertNotNull($d);
    }

    public function test_overlap_check_for_admin_assignment(): void
    {
        $a = $this->shift('07:00', '13:00');
        $b = $this->shift('12:00', '18:00');
        $c = $this->shift('18:00', '23:00');

        $this->assertNotNull(ShiftService::firstOverlap(collect([$a, $b])));
        $this->assertNull(ShiftService::firstOverlap(collect([$a, $c])));
    }

    public function test_a_shift_with_penalty_history_cannot_be_deleted(): void
    {
        $s = $this->shift('07:00', '13:00');
        $this->assertNull(ShiftService::undeletableReason($s));

        $d = $this->user('driver');
        DB::table('driver_shift_offline_penalties')->insert(['driver_id' => $d->id, 'shift_id' => $s->id, 'shift_date' => now()->toDateString(),
            'offline_seconds' => 3600, 'amount' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertStringContainsString('lịch sử', ShiftService::undeletableReason($s));

        $withDriver = $this->shift('14:00', '18:00');
        $withDriver->users()->attach($d->id);
        $this->assertStringContainsString('tài xế đăng ký', ShiftService::undeletableReason($withDriver));
    }

    public function test_shift_ends_exactly_at_its_end_time_and_codes_are_generated(): void
    {
        $morning = $this->shift('07:00', '13:00');
        $afternoon = $this->shift('13:00', '18:00');
        Carbon::setTestNow(Carbon::today()->setTime(13, 0));
        $this->assertFalse($morning->isNowInShift());
        $this->assertTrue($afternoon->isNowInShift());
        $this->assertSame([$afternoon->id], ShiftResource::scopeCurrentShifts(Shift::where('city_id', $this->city->id))->pluck('id')->all());
        Carbon::setTestNow();

        Livewire::test(CreateShift::class)->fillForm(['name' => 'Ca Sáng Sớm', 'start_time' => '05:00', 'end_time' => '06:00', 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateShift::class)->fillForm(['name' => 'Ca sáng sớm', 'start_time' => '19:00', 'end_time' => '20:00', 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertEqualsCanonicalizing(['ca-sang-som', 'ca-sang-som-2'], Shift::where('city_id', $this->city->id)->where('name', 'like', '%sáng sớm')->pluck('code')->all());
    }

    public function test_demand_is_counted_by_the_time_the_order_was_placed(): void
    {
        $afternoon = $this->shift('13:00', '18:00');
        $placed = now()->subDay()->setTime(12, 50);
        $order = Order::create([
            'code' => 'SH'.uniqid(), 'status' => 'completed', 'city_id' => $this->city->id, 'service_type' => 'delivery',
            'platform' => 'shop_app', 'payment_method' => 'cod', 'pickup_address' => 'a', 'delivery_address' => 'b',
            'shipping_fee' => 0, 'bonus_fee' => 0, 'night_surcharge' => 0, 'discount_amount' => 0, 'completed_at' => $placed->copy()->setTime(13, 20),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $placed]);

        $row = ShiftService::coverage($this->city->id)['rows']->first(fn ($r) => $r['shift']->id === $afternoon->id);
        $this->assertSame(0.0, $row['perDay'], 'đặt 12:50, giao 13:20 vẫn không thuộc ca 13:00');
    }

    public function test_admin_cannot_assign_overlapping_shifts_in_the_driver_profile(): void
    {
        $a = $this->shift('07:00', '13:00');
        $b = $this->shift('12:00', '18:00');
        $c = $this->shift('18:00', '23:00');
        $driver = $this->user('driver');

        Livewire::test(\App\Filament\Resources\DriverResource\Pages\EditDriver::class, ['record' => $driver->id])
            ->fillForm(['registeredShifts' => [$a->id, $b->id]])->call('save')->assertHasFormErrors(['registeredShifts']);
        $this->assertSame(0, $driver->registeredShifts()->count());

        Livewire::test(\App\Filament\Resources\DriverResource\Pages\EditDriver::class, ['record' => $driver->id])
            ->fillForm(['registeredShifts' => [$a->id, $c->id]])->call('save')->assertHasNoFormErrors();
        $this->assertSame(2, $driver->registeredShifts()->count());
    }
}
