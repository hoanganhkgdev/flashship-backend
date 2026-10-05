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
}
