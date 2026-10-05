<?php

namespace Tests\Feature;

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

    public function test_cannot_create_overlapping_or_zero_length_shift(): void
    {
        $this->shift('07:00', '13:00');

        Livewire::test(CreateShift::class)
            ->fillForm(['name' => 'Ca trùng', 'code' => 'dup', 'start_time' => '12:00', 'end_time' => '18:00', 'is_active' => true])
            ->call('create')->assertHasFormErrors(['end_time']);

        Livewire::test(CreateShift::class)
            ->fillForm(['name' => 'Ca rỗng', 'code' => 'zero', 'start_time' => '09:00', 'end_time' => '09:00', 'is_active' => true])
            ->call('create')->assertHasFormErrors(['end_time']);

        Livewire::test(CreateShift::class)
            ->fillForm(['name' => 'Ca chiều', 'code' => 'afternoon-x', 'start_time' => '13:00', 'end_time' => '18:00', 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('created', Shift::where('code', 'afternoon-x')->first()->logs()->first()->action);
    }

    public function test_toggle_logs_and_reactivation_is_blocked_when_it_would_overlap(): void
    {
        $a = $this->shift('07:00', '13:00');
        $b = $this->shift('12:00', '18:00', false);

        $this->assertNotNull(ShiftService::activate($b, $this->admin->id), 'bật lại sẽ trùng ca A');
        $this->assertFalse($b->fresh()->is_active);

        $d = $this->user('driver');
        $a->users()->attach($d->id);
        $this->assertSame(1, ShiftService::deactivate($a, $this->admin->id));
        $this->assertFalse($a->fresh()->is_active);
        $this->assertSame('deactivated', $a->logs()->first()->action);
        $this->assertNull(ShiftService::activate($b, $this->admin->id));
    }

    public function test_time_cannot_be_changed_while_the_shift_is_in_progress(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(10, 0));
        $s = $this->shift('07:00', '13:00');

        Livewire::test(EditShift::class, ['record' => $s->id])
            ->fillForm(['start_time' => '08:00', 'end_time' => '13:00'])->call('save');
        $this->assertSame('07:00', substr($s->fresh()->start_time, 0, 5));

        Carbon::setTestNow(Carbon::today()->setTime(15, 0));
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

    public function test_shift_scoring_skips_locked_drivers(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(13, 1));
        $s = $this->shift('07:00', '13:00');
        $active = $this->user('driver');
        $locked = $this->user('driver', 2);
        $s->users()->attach([$active->id, $locked->id]);
        DB::table('shift_user')->where('shift_id', $s->id)->update(['created_at' => now()->subDays(3)]); // đã đăng ký từ trước ca
        DB::table('users')->whereIn('id', [$active->id, $locked->id])->update(['driver_score' => 100]);

        $this->artisan('drivers:score-shift-sessions');

        $runs = DB::table('driver_shift_score_runs')->where('shift_id', $s->id)->pluck('driver_id')->all();
        $this->assertContains($active->id, $runs);
        $this->assertNotContains($locked->id, $runs);
        $this->assertSame(100, (int) DB::table('users')->where('id', $locked->id)->value('driver_score'));
        Carbon::setTestNow();
    }

    public function test_pages_render(): void
    {
        $s = $this->shift('07:00', '13:00');
        $s->users()->attach($this->user('driver')->id);

        Livewire::test(ListShifts::class)->assertSuccessful()->assertCanSeeTableRecords([$s]);
        Livewire::test(ViewShift::class, ['record' => $s->id])->assertSuccessful()->assertSee('Tài xế đăng ký ca');
    }
}
