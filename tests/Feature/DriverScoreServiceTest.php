<?php

namespace Tests\Feature;

use App\Services\DriverScoreReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Services\DriverScoreService;
use Tests\TestCase;

class DriverScoreServiceTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Score city', 'slug' => 'score-city-'.uniqid(), 'is_active' => true]);
    }

    private function driver(int $score, int $status = 1): User
    {
        $u = User::create([
            'name' => 'Score driver', 'email' => 'sc-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => $status, 'city_id' => $this->city->id,
        ]);
        DB::table('users')->where('id', $u->id)->update(['driver_score' => $score]);

        return $u;
    }

    private function adjust(int $id, int $delta, string $reason): void
    {
        $m = new \ReflectionMethod(DriverScoreService::class, 'adjust');
        $m->setAccessible(true);
        $m->invoke(null, $id, $delta, $reason);
    }

    public function test_log_records_real_delta_when_clamped_at_floor(): void
    {
        $d = $this->driver(4);
        $this->adjust($d->id, -15, 'shift_online_critical');

        $log = DB::table('driver_score_logs')->where('driver_id', $d->id)->first();
        $this->assertSame(-4, (int) $log->delta);
        $this->assertSame(0, (int) $log->score_after);
        $this->assertSame(0, (int) DB::table('users')->where('id', $d->id)->value('driver_score'));
    }

    public function test_manual_reset_is_logged_with_reason_and_actor(): void
    {
        $d = $this->driver(35);
        DB::table('users')->where('id', $d->id)->update(['consecutive_completed' => 7]);

        DriverScoreService::resetToDefault($d->id, 'Khiếu nại đúng', 99);

        $log = DB::table('driver_score_logs')->where('driver_id', $d->id)->first();
        $this->assertSame('manual_reset', $log->reason);
        $this->assertSame(DriverScoreService::DEFAULT_SCORE - 35, (int) $log->delta);
        $this->assertSame('Khiếu nại đúng', $log->note);
        $this->assertSame(99, (int) $log->performed_by);
        $this->assertSame(0, (int) DB::table('users')->where('id', $d->id)->value('consecutive_completed'));
    }

    public function test_projection_matches_what_weekly_command_settles(): void
    {
        $bonusScore = DriverScoreService::weeklyBonusScore($this->city->id);
        $penaltyScore = DriverScoreService::weeklyPenaltyScore($this->city->id);
        $ids = [
            $this->driver($bonusScore)->id, $this->driver($bonusScore + 5)->id,
            $this->driver($penaltyScore)->id, $this->driver($penaltyScore - 5)->id, $this->driver(100)->id,
            $this->driver($bonusScore + 10, 2)->id, // bị khóa: không tính
        ];

        $p = DriverScoreReport::projection($this->city->id);
        $this->assertSame(2, $p['bonus']);
        $this->assertSame(2, $p['penalty']);

        $this->artisan('drivers:weekly-score');
        $settled = DB::table('driver_score_settlements')->whereIn('driver_id', $ids)->get();
        $this->assertSame($p['bonus'], $settled->where('type', 'bonus')->count());
        $this->assertSame($p['penalty'], $settled->where('type', 'penalty')->count());
        $this->assertSame($p['bonusMoney'], (int) $settled->where('type', 'bonus')->sum('amount'));
        $this->assertSame($p['penaltyMoney'], (int) $settled->where('type', 'penalty')->sum('amount'));
    }

    public function test_pages_render_and_reset_action_requires_a_reason(): void
    {
        $admin = $this->driver(100);
        DB::table('users')->where('id', $admin->id)->update(['user_type' => 'admin']);
        $this->actingAs(User::find($admin->id));
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        \Filament\Facades\Filament::setTenant($this->city);

        $d = $this->driver(40);
        $this->adjust($d->id, -3, 'decline');

        \Livewire\Livewire::test(\App\Filament\Resources\DriverScoreResource\Pages\ListDriverScores::class)
            ->assertSuccessful()->assertCanSeeTableRecords([$d]);

        \Livewire\Livewire::test(\App\Filament\Resources\DriverScoreResource\Pages\ListDriverScores::class)
            ->callTableAction('reset_score', $d, ['note' => ''])
            ->assertHasTableActionErrors(['note' => 'required']);

        \Livewire\Livewire::test(\App\Filament\Resources\DriverScoreResource\Pages\ListDriverScores::class)
            ->callTableAction('reset_score', $d, ['note' => 'Kiểm thử'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(100, (int) DB::table('users')->where('id', $d->id)->value('driver_score'));

        \Livewire\Livewire::test(\App\Filament\Resources\DriverScoreResource\Pages\ViewDriverScore::class, ['record' => $d->id])
            ->assertSuccessful()->assertSee('Điểm 30 ngày')->assertSee('Lần đặt lại gần nhất');
    }
}
