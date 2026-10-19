<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\Shift;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Driver\Services\ShiftOfflineService;
use Tests\TestCase;

/** Mỗi ca được Offline tối đa 30 phút; quá thì tự động phạt 50.000đ vào công nợ (một lần mỗi ca). */
class ShiftOfflinePenaltyTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Offline city', 'slug' => 'off-'.uniqid(), 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function driver(int $status = 1): User
    {
        return User::create([
            'name' => 'Tài xế off', 'email' => 'off-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => $status, 'city_id' => $this->city->id,
        ]);
    }

    private function shift(string $start, string $end): Shift
    {
        return Shift::create(['city_id' => $this->city->id, 'code' => 'c'.uniqid(), 'name' => 'Ca '.$start, 'start_time' => $start, 'end_time' => $end, 'is_active' => true]);
    }

    /** Đăng ký ca từ vài ngày trước để tính từ đầu ca. */
    private function register(Shift $shift, User $driver): void
    {
        $shift->users()->attach($driver->id);
        DB::table('shift_user')->where('shift_id', $shift->id)->where('user_id', $driver->id)->update(['created_at' => now()->subDays(5)]);
    }

    private function online(User $driver, string $from, ?string $to = null): void
    {
        DriverShiftSession::create(['driver_id' => $driver->id, 'started_at' => Carbon::parse($from), 'ended_at' => $to ? Carbon::parse($to) : null]);
    }

    public function test_offline_over_thirty_minutes_creates_one_fifty_thousand_penalty_debt(): void
    {
        $shift = $this->shift('07:00', '16:00');
        $driver = $this->driver();
        $this->register($shift, $driver);
        $service = app(ShiftOfflineService::class);

        // 07:25 — mới trễ 25 phút: chưa phạt.
        Carbon::setTestNow(Carbon::today()->setTime(7, 25));
        $this->assertSame(0, $service->check());

        // 07:31 — off 31 phút: phạt.
        Carbon::setTestNow(Carbon::today()->setTime(7, 31));
        $this->assertSame(1, $service->check());

        $debt = DB::table('driver_debts')->where('driver_id', $driver->id)->first();
        $this->assertSame(50000, (int) $debt->amount_due);
        $this->assertSame('pending', $debt->status);
        $this->assertStringStartsWith('shift_offline_', $debt->ref_id);

        // Off thêm nữa cũng không bị phạt lần hai trong cùng ca.
        Carbon::setTestNow(Carbon::today()->setTime(12, 0));
        $this->assertSame(0, $service->check());
        $this->assertSame(1, DB::table('driver_debts')->where('driver_id', $driver->id)->count());
        $this->assertSame(1, DB::table('driver_shift_offline_penalties')->where('driver_id', $driver->id)->count());
    }

    public function test_time_spent_online_does_not_count_towards_the_limit(): void
    {
        $shift = $this->shift('07:00', '16:00');
        $driver = $this->driver();
        $this->register($shift, $driver);
        // Online từ 07:10 đến hết ca: chỉ off 10 phút đầu.
        $this->online($driver, Carbon::today()->setTime(7, 10)->toDateTimeString());

        Carbon::setTestNow(Carbon::today()->setTime(15, 0));

        $this->assertSame(0, app(ShiftOfflineService::class)->check());
        $this->assertSame(0, DB::table('driver_debts')->where('driver_id', $driver->id)->count());
    }

    public function test_several_short_offline_breaks_add_up(): void
    {
        $shift = $this->shift('07:00', '16:00');
        $driver = $this->driver();
        $this->register($shift, $driver);
        // Online 07:00–10:00, off 20 phút, online 10:20–12:00, off 20 phút nữa → tổng 40 phút.
        $this->online($driver, Carbon::today()->setTime(7, 0)->toDateTimeString(), Carbon::today()->setTime(10, 0)->toDateTimeString());
        $this->online($driver, Carbon::today()->setTime(10, 20)->toDateTimeString(), Carbon::today()->setTime(12, 0)->toDateTimeString());
        $this->online($driver, Carbon::today()->setTime(12, 20)->toDateTimeString());

        Carbon::setTestNow(Carbon::today()->setTime(13, 0));

        $this->assertSame(1, app(ShiftOfflineService::class)->check());
    }

    public function test_locked_drivers_leave_days_and_late_registrations_are_not_penalized(): void
    {
        $shift = $this->shift('07:00', '16:00');
        $locked = $this->driver(2);
        $onLeave = $this->driver();
        $late = $this->driver();
        $this->register($shift, $locked);
        $this->register($shift, $onLeave);
        $shift->users()->attach($late->id); // đăng ký vào đúng lúc đang giữa ca
        DB::table('driver_leave_requests')->insert(['driver_id' => $onLeave->id, 'leave_date' => today(), 'note' => 'x', 'created_by' => $onLeave->id, 'created_at' => now(), 'updated_at' => now()]);

        Carbon::setTestNow(Carbon::today()->setTime(12, 0));
        DB::table('shift_user')->where('user_id', $late->id)->update(['created_at' => Carbon::today()->setTime(11, 50)]);

        $this->assertSame(0, app(ShiftOfflineService::class)->check());
    }

    public function test_overnight_shift_is_counted_by_its_start_date(): void
    {
        $shift = $this->shift('18:00', '01:30');
        $driver = $this->driver();
        $this->register($shift, $driver);
        // Online suốt từ 18:00 đến 00:50 rồi off — chưa đủ 30 phút off lúc 01:10.
        $this->online($driver, Carbon::yesterday()->setTime(18, 0)->toDateTimeString(), Carbon::today()->setTime(0, 50)->toDateTimeString());

        Carbon::setTestNow(Carbon::today()->setTime(1, 10));
        $this->assertSame(0, app(ShiftOfflineService::class)->check());

        // 01:25 — đã off 35 phút (00:50 → 01:25): phạt, gắn với ngày bắt đầu ca (hôm qua).
        Carbon::setTestNow(Carbon::today()->setTime(1, 25));
        $this->assertSame(1, app(ShiftOfflineService::class)->check());
        $this->assertSame(Carbon::yesterday()->toDateString(), (string) DB::table('driver_shift_offline_penalties')->where('driver_id', $driver->id)->value('shift_date'));
    }

    public function test_outside_the_shift_nothing_happens_and_penalty_debt_goes_overdue_like_other_penalties(): void
    {
        $shift = $this->shift('07:00', '16:00');
        $driver = $this->driver();
        $this->register($shift, $driver);

        Carbon::setTestNow(Carbon::today()->setTime(20, 0));
        $this->assertSame(0, app(ShiftOfflineService::class)->check());

        Carbon::setTestNow(Carbon::today()->setTime(8, 0));
        app(ShiftOfflineService::class)->check();
        DB::table('driver_debts')->where('driver_id', $driver->id)->update(['created_at' => now()->subHours(30)]);

        $this->artisan('driver:mark-overdue-debts');
        $this->assertSame('overdue', DB::table('driver_debts')->where('driver_id', $driver->id)->value('status'));
    }

    public function test_limit_and_amount_come_from_the_city_settings(): void
    {
        OperationalSettings::put(['shift.offline_limit_minutes' => 10, 'shift.offline_penalty_amount' => 80000], $this->city->id);
        $shift = $this->shift('07:00', '16:00');
        $driver = $this->driver();
        $this->register($shift, $driver);

        Carbon::setTestNow(Carbon::today()->setTime(7, 9));
        $this->assertSame(0, app(ShiftOfflineService::class)->check());

        Carbon::setTestNow(Carbon::today()->setTime(7, 11));
        $this->assertSame(1, app(ShiftOfflineService::class)->check());
        $this->assertSame(80000, (int) DB::table('driver_debts')->where('driver_id', $driver->id)->value('amount_due'));
        $this->assertStringContainsString('quá 10 phút', DB::table('driver_debts')->where('driver_id', $driver->id)->value('note'));
    }

    public function test_status_reports_remaining_minutes_for_the_running_shift(): void
    {
        $shift = $this->shift('07:00', '16:00');
        $driver = $this->driver();
        $this->register($shift, $driver);
        $service = app(ShiftOfflineService::class);

        Carbon::setTestNow(Carbon::today()->setTime(6, 0));
        $this->assertNull($service->status($driver), 'ngoài ca thì không có trạng thái');

        // 07:12 vẫn offline từ đầu ca → đã off 12 phút, còn 18 phút.
        Carbon::setTestNow(Carbon::today()->setTime(7, 12));
        $status = $service->status($driver);
        $this->assertSame(1800, $status['limit_seconds']);
        $this->assertSame(720, $status['offline_seconds']);
        $this->assertSame(1080, $status['remaining_seconds']);
        $this->assertFalse($status['penalized']);
        $this->assertSame(50000, $status['penalty_amount']);

        // Online từ 07:12 thì số phút còn lại giữ nguyên.
        $this->online($driver, Carbon::today()->setTime(7, 12)->toDateTimeString());
        Carbon::setTestNow(Carbon::today()->setTime(9, 0));
        $this->assertSame(1080, $service->status($driver)['remaining_seconds']);
    }
}
