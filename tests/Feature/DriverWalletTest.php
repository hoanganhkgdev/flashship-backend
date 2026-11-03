<?php

namespace Tests\Feature;

use App\Filament\Resources\DriverWalletResource\Pages\ListDriverWallets;
use App\Filament\Resources\DriverWalletResource\Pages\ViewDriverWallet;
use App\Services\DriverWalletReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverWallet;
use Modules\Driver\Services\DriverWalletService;
use Tests\TestCase;

class DriverWalletTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Wallet city', 'slug' => 'wallet-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->driver = $this->user('driver');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, int $status = 1): User
    {
        return User::create([
            'name' => ucfirst($type).' wallet', 'email' => 'wl-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => $status, 'city_id' => $this->city->id,
        ]);
    }

    public function test_admin_adjustments_in_the_same_second_are_both_recorded_with_actor(): void
    {
        DriverWalletService::adminAdjust($this->driver->id, 100000, 'credit', 'Hỗ trợ đơn A', $this->admin->id);
        DriverWalletService::adminAdjust($this->driver->id, 100000, 'credit', 'Hỗ trợ đơn B', $this->admin->id);

        $wallet = DriverWallet::where('driver_id', $this->driver->id)->first();
        $this->assertSame(200000.0, $wallet->balance);
        $tx = $wallet->transactions()->get();
        $this->assertCount(2, $tx);
        $this->assertSame([$this->admin->id], $tx->pluck('performed_by')->unique()->values()->all());
    }

    public function test_admin_debit_cannot_make_wallet_negative(): void
    {
        DriverWalletService::adminAdjust($this->driver->id, 50000, 'credit', 'Nạp thử', $this->admin->id);
        $this->expectExceptionMessage('Số dư không đủ');
        DriverWalletService::adminAdjust($this->driver->id, 80000, 'debit', 'Trừ quá', $this->admin->id);
    }

    public function test_categories_are_derived_from_reference(): void
    {
        $this->assertSame('rain', DriverWalletReport::categoryOf('order_12_rain'));
        $this->assertSame('withdraw_hold', DriverWalletReport::categoryOf('withdraw_hold_5'));
        $this->assertSame('withdraw', DriverWalletReport::categoryOf('withdraw_9'));
        $this->assertSame('admin', DriverWalletReport::categoryOf('admin_adj_3_abc'));
        $this->assertSame('other', DriverWalletReport::categoryOf('zzz'));

        // SQL và PHP phải phân loại giống nhau.
        DriverWalletService::adjust($this->driver->id, 5000, 'credit', 'x', 'order_77_rain');
        DriverWalletService::adjust($this->driver->id, 6000, 'credit', 'x', 'withdraw_refund_77');
        $sql = DB::table('driver_wallet_transactions')->whereIn('reference', ['order_77_rain', 'withdraw_refund_77'])
            ->selectRaw('reference, '.DriverWalletReport::categorySql().' k')->pluck('k', 'reference');
        foreach ($sql as $ref => $k) {
            $this->assertSame(DriverWalletReport::categoryOf($ref), $k);
        }
    }

    public function test_reconciliation_flags_a_tampered_balance(): void
    {
        DriverWalletService::adjust($this->driver->id, 90000, 'credit', 'x', 'order_1_rain');
        $this->assertSame(0, DriverWalletReport::reconciliation($this->city->id)['bad']);

        DB::table('driver_wallets')->where('driver_id', $this->driver->id)->update(['balance' => 1]);
        $r = DriverWalletReport::reconciliation($this->city->id);
        $this->assertSame(1, $r['bad']);
        $this->assertSame([$this->driver->id], $r['sample']);
    }

    public function test_summary_separates_active_and_locked_money(): void
    {
        $locked = $this->user('driver', 2);
        DriverWalletService::adjust($this->driver->id, 100000, 'credit', 'x', 'order_2_rain');
        DriverWalletService::adjust($locked->id, 40000, 'credit', 'x', 'order_3_rain');

        $s = DriverWalletReport::summary($this->city->id);
        $this->assertSame(100000, $s['activeMoney']);
        $this->assertSame(40000, $s['lockedMoney']);
        $this->assertSame(1, $s['lockedWithMoney']);
    }

    public function test_pages_render_and_adjust_action_validates(): void
    {
        DriverWalletService::adjust($this->driver->id, 100000, 'credit', 'x', 'order_4_rain');
        $wallet = DriverWallet::where('driver_id', $this->driver->id)->first();

        Livewire::test(ListDriverWallets::class)->assertSuccessful()->assertCanSeeTableRecords([$wallet]);

        Livewire::test(ListDriverWallets::class)
            ->callTableAction('adjust', $wallet, ['type' => 'credit', 'amount' => 20000, 'description' => 'ab'])
            ->assertHasTableActionErrors(['description']);

        // Từ mức lớn phải nhập lại đúng số tiền.
        Livewire::test(ListDriverWallets::class)
            ->callTableAction('adjust', $wallet, ['type' => 'credit', 'amount' => 6000000, 'confirm_amount' => 600000, 'description' => 'Hỗ trợ lớn'])
            ->assertHasTableActionErrors(['confirm_amount']);

        Livewire::test(ListDriverWallets::class)
            ->callTableAction('adjust', $wallet, ['type' => 'credit', 'amount' => 20000, 'description' => 'Hỗ trợ đơn C'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(120000.0, $wallet->fresh()->balance);

        Livewire::test(ViewDriverWallet::class, ['record' => $wallet->id])->assertSuccessful()->assertSee('Tiền vào/ra theo nguồn');
    }

    private function bank(): void
    {
        \Modules\Driver\Models\Bank::create(['user_id' => $this->driver->id, 'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '0123456789', 'account_name' => 'TAI XE']);
    }

    private function overdueDebt(int $due, int $paid = 0): void
    {
        DB::table('driver_debts')->insert(['driver_id' => $this->driver->id, 'city_id' => $this->city->id, 'debt_type' => 'weekly', 'status' => 'overdue',
            'amount_due' => $due, 'amount_paid' => $paid, 'week_start' => now()->subWeek()->startOfWeek()->toDateString(),
            'week_end' => now()->subWeek()->endOfWeek()->toDateString(), 'note' => 'Phí tuần', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_withdrawals_must_be_whole_dong_within_the_cap(): void
    {
        $this->bank();
        DriverWalletService::adjust($this->driver->id, 200000, 'credit', 'x', 'order_10_rain');
        \Laravel\Sanctum\Sanctum::actingAs($this->driver);

        $this->postJson('/api/wallet/withdraw', ['amount' => 150000.5])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson('/api/wallet/withdraw', ['amount' => DriverWalletService::MAX_WITHDRAW + 1])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertSame(0, DB::table('withdraw_requests')->where('driver_id', $this->driver->id)->count());

        $this->postJson('/api/wallet/withdraw', ['amount' => 150000])->assertOk();
        $this->assertSame(50000.0, DriverWallet::where('driver_id', $this->driver->id)->first()->balance);
    }

    public function test_overdue_debt_is_held_back_from_withdrawals(): void
    {
        $this->bank();
        DriverWalletService::adjust($this->driver->id, 500000, 'credit', 'x', 'order_11_rain');
        $this->overdueDebt(350000);
        \Laravel\Sanctum\Sanctum::actingAs($this->driver);

        $this->getJson('/api/wallet')->assertOk()->assertJsonPath('data.overdue_debt', 350000)->assertJsonPath('data.withdrawable', 150000);

        $this->postJson('/api/wallet/withdraw', ['amount' => 200000])->assertStatus(422)->assertJsonPath('code', 'debt_hold');
        $this->assertSame(500000.0, DriverWallet::where('driver_id', $this->driver->id)->first()->balance, 'không giữ tiền khi bị chặn');

        $this->postJson('/api/wallet/withdraw', ['amount' => 150000])->assertOk();
        $this->assertSame(350000.0, DriverWallet::where('driver_id', $this->driver->id)->first()->balance, 'phần nợ vẫn nằm trong ví để thu');
    }

    public function test_the_debt_hold_ignores_drivers_exempt_from_debt_and_paid_debts(): void
    {
        $this->bank();
        DriverWalletService::adjust($this->driver->id, 500000, 'credit', 'x', 'order_12_rain');
        $this->overdueDebt(350000, 350000); // đã thu đủ
        \Laravel\Sanctum\Sanctum::actingAs($this->driver);
        $this->postJson('/api/wallet/withdraw', ['amount' => 100000])->assertOk();

        $this->overdueDebt(300000);
        $this->driver->update(['debt_enabled' => false]);
        $this->postJson('/api/wallet/withdraw', ['amount' => 100000])->assertOk();
    }

    public function test_transaction_history_has_a_stable_order_across_pages(): void
    {
        $wallet = DriverWalletService::ensureWallet($this->driver->id);
        $at = now()->startOfSecond();
        foreach (range(1, 25) as $i) {
            DB::table('driver_wallet_transactions')->insert(['wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 1000, 'reference' => 'order_'.(900 + $i).'_bonus', 'created_at' => $at, 'updated_at' => $at]);
        }
        \Laravel\Sanctum\Sanctum::actingAs($this->driver);

        $ids = array_merge(
            array_column($this->getJson('/api/wallet/transactions?page=1')->assertOk()->json('data'), 'id'),
            array_column($this->getJson('/api/wallet/transactions?page=2')->assertOk()->json('data'), 'id'),
        );
        $this->assertCount(25, array_unique($ids), 'không trùng, không sót dòng giữa các trang');
        $this->assertSame($ids, collect($ids)->sortDesc()->values()->all(), 'cùng giây thì mới nhất (id lớn) trước');
    }

    public function test_refund_of_an_excess_qr_payment_is_not_labelled_as_a_top_up(): void
    {
        $this->assertSame('debt_refund', DriverWalletReport::categoryOf('payos_debt_excess_123456'));
        $this->assertSame('payos', DriverWalletReport::categoryOf('payos_123456'));

        DriverWalletService::adjust($this->driver->id, 30000, 'credit', 'x', 'payos_debt_excess_555');
        $keys = DriverWalletReport::cashflow($this->city->id)->pluck('key')->all();
        $this->assertContains('debt_refund', $keys);
        $this->assertNotContains('payos', $keys);
    }

    public function test_wallet_creation_is_idempotent_and_adjust_creates_a_missing_wallet(): void
    {
        $a = DriverWalletService::ensureWallet($this->driver->id);
        $b = DriverWalletService::ensureWallet($this->driver->id);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, DriverWallet::where('driver_id', $this->driver->id)->count());

        $other = $this->user('driver');
        DriverWalletService::adjust($other->id, 10000, 'credit', 'x', 'order_20_rain');
        $this->assertSame(10000.0, DriverWallet::where('driver_id', $other->id)->first()->balance);
    }

    public function test_manual_adjustments_are_whole_dong_and_capped(): void
    {
        foreach ([999, DriverWalletService::ADJUST_MAX + 1] as $bad) {
            try {
                DriverWalletService::adminAdjust($this->driver->id, $bad, 'credit', 'Sai mức', $this->admin->id);
                $this->fail('phải từ chối '.$bad);
            } catch (\Exception $e) {
                $this->assertStringContainsString('từ 1.000', $e->getMessage());
            }
        }
        DriverWalletService::adminAdjust($this->driver->id, 12000.4, 'credit', 'Làm tròn', $this->admin->id);
        $this->assertSame(12000.0, DriverWallet::where('driver_id', $this->driver->id)->first()->balance);
    }

    public function test_reconciliation_and_summary_never_leak_other_cities(): void
    {
        DriverWalletService::adjust($this->driver->id, 90000, 'credit', 'x', 'order_30_rain');
        DB::table('driver_wallets')->where('driver_id', $this->driver->id)->update(['balance' => 1]); // lệch sổ cái

        $this->assertSame(1, DriverWalletReport::reconciliation($this->city->id)['bad']);
        $this->assertSame(0, DriverWalletReport::reconciliation(null)['total'], 'không có khu vực thì không có ví nào');
        $this->assertSame(0, DriverWalletReport::summary(null)['activeCount']);
    }

    public function test_search_by_phone_and_manage_permission(): void
    {
        DriverWalletService::adjust($this->driver->id, 50000, 'credit', 'x', 'order_40_rain');
        $mine = DriverWallet::where('driver_id', $this->driver->id)->first();
        $otherDriver = $this->user('driver');
        DriverWalletService::adjust($otherDriver->id, 50000, 'credit', 'x', 'order_41_rain');
        $other = DriverWallet::where('driver_id', $otherDriver->id)->first();

        Livewire::test(ListDriverWallets::class)->searchTable($this->driver->phone)
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);

        $this->assertTrue(\App\Filament\Resources\DriverWalletResource::canManage());
        $this->actingAs($this->user('viewer'));
        $this->assertFalse(\App\Filament\Resources\DriverWalletResource::canManage());
    }
}
