<?php

namespace Tests\Feature;

use App\Filament\Resources\DriverDebtResource\Pages\CreateDriverDebt;
use App\Filament\Resources\DriverDebtResource\Pages\ListDriverDebts;
use App\Filament\Resources\DriverDebtResource\Pages\ViewDriverDebt;
use App\Services\DriverDebtService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverDebt;
use Modules\Driver\Models\DriverWallet;
use Modules\Driver\Services\DriverWalletService;
use Tests\TestCase;

class DriverDebtTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Debt city', 'slug' => 'debt-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->driver = $this->user('driver');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, int $status = 1): User
    {
        return User::create([
            'name' => ucfirst($type).' debt', 'email' => 'db-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => $status, 'city_id' => $this->city->id,
        ]);
    }

    private function debt(int $due = 350000, string $status = 'overdue', ?User $driver = null): DriverDebt
    {
        return DriverDebt::create([
            'driver_id' => ($driver ?? $this->driver)->id, 'debt_type' => 'weekly', 'status' => $status, 'amount_due' => $due, 'amount_paid' => 0,
            'week_start' => now()->subWeek()->startOfWeek()->toDateString(), 'week_end' => now()->subWeek()->endOfWeek()->toDateString(),
            'note' => 'Phí tuần test',
        ]);
    }

    private function wallet(int $amount): void
    {
        DriverWalletService::adjust($this->driver->id, $amount, 'credit', 'nạp', 'seed_'.uniqid());
    }

    private function actions(DriverDebt $d): array
    {
        return $d->logs()->pluck('action')->all();
    }

    public function test_partial_wallet_payment_keeps_debt_open_then_closes_it(): void
    {
        $this->wallet(120000);
        $d = $this->debt();

        $r = DriverDebtService::payFromWallet($d, 120000, $this->admin->id);
        $this->assertTrue($r['ok']);
        $d->refresh();
        $this->assertSame('overdue', $d->status, 'còn nợ thì vẫn quá hạn');
        $this->assertSame(120000.0, (float) $d->amount_paid);
        $this->assertSame(0.0, DriverWallet::where('driver_id', $this->driver->id)->first()->balance);

        $this->wallet(230000);
        $this->assertTrue(DriverDebtService::payFromWallet($d, 230000, $this->admin->id)['ok']);
        $d->refresh();
        $this->assertSame('paid', $d->status);
        $this->assertSame('wallet', $d->paid_via);
        $this->assertNotNull($d->paid_at);
        $this->assertSame(['paid_wallet', 'paid_wallet'], $this->actions($d));
    }

    public function test_wallet_payment_cannot_exceed_balance_or_remaining_and_does_not_change_the_debt(): void
    {
        $this->wallet(50000);
        $d = $this->debt();

        $this->assertFalse(DriverDebtService::payFromWallet($d, 100000, $this->admin->id)['ok']); // ví không đủ
        $this->assertFalse(DriverDebtService::payFromWallet($d, 400000, $this->admin->id)['ok']); // vượt nợ
        $d->refresh();
        $this->assertSame(0.0, (float) $d->amount_paid);
        $this->assertSame([], $this->actions($d));
        $this->assertSame(50000.0, DriverWallet::where('driver_id', $this->driver->id)->first()->balance);
    }

    public function test_manual_collection_records_without_touching_the_wallet(): void
    {
        $this->wallet(10000);
        $d = $this->debt(100000, 'pending');

        $this->assertFalse(DriverDebtService::collectManual($d, 100000, '', $this->admin->id)['ok']);
        $this->assertTrue(DriverDebtService::collectManual($d, 100000, 'Tiền mặt', $this->admin->id)['ok']);
        $d->refresh();
        $this->assertSame('paid', $d->status);
        $this->assertSame('manual', $d->paid_via);
        $this->assertSame(10000.0, DriverWallet::where('driver_id', $this->driver->id)->first()->balance);
        $this->assertSame('Tiền mặt', $d->logs()->first()->note);
    }

    public function test_waive_reduces_what_is_owed_and_requires_a_reason(): void
    {
        $d = $this->debt(350000);
        DriverDebtService::collectManual($d, 100000, 'CK tay', $this->admin->id);

        $this->assertFalse(DriverDebtService::waive($d, 'ab', $this->admin->id)['ok']);
        $this->assertTrue(DriverDebtService::waive($d, 'Nghỉ phép có lý do', $this->admin->id)['ok']);
        $d->refresh();
        $this->assertSame('paid', $d->status);
        $this->assertSame(100000.0, (float) $d->amount_due, 'số phải thu hạ về phần đã thu');
        $this->assertSame('waived', $d->paid_via);
        $this->assertSame(250000.0, (float) $d->logs()->where('action', 'waived')->first()->amount);
    }

    public function test_adjust_cannot_go_below_what_was_paid_and_is_logged(): void
    {
        $d = $this->debt(350000);
        DriverDebtService::collectManual($d, 100000, 'CK tay', $this->admin->id);

        $this->assertFalse(DriverDebtService::adjustAmount($d, 50000, 'sửa nhầm', $this->admin->id)['ok']);
        $this->assertTrue(DriverDebtService::adjustAmount($d, 300000, 'Giảm phí theo thỏa thuận', $this->admin->id)['ok']);
        $this->assertSame(300000.0, (float) $d->fresh()->amount_due);
        $this->assertContains('adjusted', $this->actions($d));

        $paid = $this->debt(100000, 'paid');
        $paid->update(['amount_paid' => 100000]);
        $this->assertFalse(DriverDebtService::adjustAmount($paid, 50000, 'đã đóng rồi', $this->admin->id)['ok']);
    }

    public function test_remind_is_rate_limited_and_needs_a_device(): void
    {
        $d = $this->debt();
        $this->assertStringContainsString('thiết bị', DriverDebtService::remind($d, $this->admin->id)['message']);
        $this->assertSame([], $this->actions($d));

        DB::table('users')->where('id', $this->driver->id)->update(['fcm_token' => 'tok']);
        DriverDebtService::log($d->id, 'reminded', 0, $this->admin->id); // đã nhắc vừa xong
        $this->assertStringContainsString('Chờ', DriverDebtService::remind($d->fresh(), $this->admin->id)['message']);
    }

    public function test_summary_counts_blocked_active_drivers_and_locked_debt(): void
    {
        $locked = $this->user('driver', 2);
        $this->debt(350000, 'overdue');
        $this->debt(100000, 'overdue', $locked);
        $this->debt(350000, 'paid');

        $s = DriverDebtService::summary($this->city->id);
        $this->assertSame(450000, $s['overdueMoney']);
        $this->assertSame(1, $s['blockedDrivers']);
        $this->assertSame(350000, $s['blockedMoney']);
        $this->assertSame(100000, $s['lockedMoney']);
        $this->assertSame($this->driver->id, (int) DriverDebtService::blockedDrivers($this->city->id)->first()->id);
    }

    public function test_pages_render_there_is_no_free_edit_or_delete(): void
    {
        $d = $this->debt();

        Livewire::test(ListDriverDebts::class)->assertSuccessful()->assertCanSeeTableRecords([$d])
            ->assertTableActionDoesNotExist('delete')->assertTableActionDoesNotExist('edit');
        Livewire::test(ViewDriverDebt::class, ['record' => $d->id])->assertSuccessful()->assertSee('Nhật ký');
        $this->assertFalse(\App\Filament\Resources\DriverDebtResource::hasPage('edit'));

        Livewire::test(ListDriverDebts::class)
            ->callTableAction('waive', $d, ['reason' => 'ab'])->assertHasTableActionErrors(['reason']);
        Livewire::test(ListDriverDebts::class)
            ->callTableAction('waive', $d, ['reason' => 'Miễn do nghỉ ốm'])->assertHasNoTableActionErrors();
        $this->assertSame('paid', $d->fresh()->status);
    }

    public function test_manual_creation_logs_who_and_why(): void
    {
        Livewire::test(CreateDriverDebt::class)
            ->fillForm(['driver_id' => $this->driver->id, 'debt_type' => 'cod', 'amount_due' => 80000, 'note' => 'Thu hộ đơn 123 chưa nộp'])
            ->call('create')->assertHasNoFormErrors();

        $d = DriverDebt::where('driver_id', $this->driver->id)->firstOrFail();
        $this->assertSame('cod', $d->debt_type);
        $this->assertSame('created', $d->logs()->first()->action);
        $this->assertSame($this->admin->id, (int) $d->logs()->first()->performed_by);
    }

    public function test_payos_payment_closes_the_debt_and_is_logged(): void
    {
        $d = $this->debt(350000);
        $id = DB::table('payment_orders')->insertGetId([
            'driver_id' => $this->driver->id, 'type' => 'debt_payment', 'ref_id' => $d->id, 'order_code' => random_int(100000000, 999999999),
            'amount' => 350000, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $m = new \ReflectionMethod(\Modules\Driver\Http\Controllers\PaymentController::class, 'handlePaid');
        $m->setAccessible(true);
        $m->invoke(new \Modules\Driver\Http\Controllers\PaymentController(), DB::table('payment_orders')->find($id));

        $d->refresh();
        $this->assertSame('paid', $d->status);
        $this->assertSame('payos', $d->paid_via);
        $log = $d->logs()->first();
        $this->assertSame('paid_payos', $log->action);
        $this->assertSame(350000.0, $log->amount);
        $this->assertNull($log->performed_by);
    }
}
