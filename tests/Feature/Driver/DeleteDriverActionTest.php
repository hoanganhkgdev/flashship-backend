<?php

namespace Tests\Feature\Driver;

use App\Filament\Resources\DriverResource\Pages\ListDrivers;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

class DeleteDriverActionTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $type): User
    {
        return User::create([
            'name' => 'Delete driver test',
            'email' => 'del-driver-'.uniqid().'@test.local',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => $type,
            'status' => 1,
            'city_id' => Filament::getTenant()?->id,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->actingAs($this->makeUser('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant(City::create(['name' => 'Test city', 'slug' => 'test-city-'.uniqid(), 'is_active' => true]));
    }

    public function test_driver_without_active_order_is_deleted(): void
    {
        $driver = $this->makeUser('driver');

        Livewire::test(ListDrivers::class)
            ->callTableAction('delete', $driver)
            ->assertHasNoTableActionErrors();

        $this->assertNull(User::find($driver->id));
    }

    public function test_driver_holding_an_active_order_cannot_be_deleted(): void
    {
        $driver = $this->makeUser('driver');
        Order::create([
            'code' => 'DEL'.uniqid(), 'status' => 'assigned', 'delivery_man_id' => $driver->id,
            'pickup_address' => 'a', 'delivery_address' => 'b', 'service_type' => 'delivery',
        ]);

        Livewire::test(ListDrivers::class)->callTableAction('delete', $driver);

        $this->assertNotNull(User::find($driver->id));
    }

    public function test_driver_with_only_completed_orders_can_be_deleted(): void
    {
        // Quyết định 24/10: tài xế đã có lịch sử đơn vẫn xóa được; đơn cũ còn nhưng mất thông tin tài xế.
        $driver = $this->makeUser('driver');
        $order = Order::create([
            'code' => 'DEL'.uniqid(), 'status' => 'completed', 'delivery_man_id' => $driver->id,
            'pickup_address' => 'a', 'delivery_address' => 'b', 'service_type' => 'delivery',
        ]);

        Livewire::test(ListDrivers::class)->callTableAction('delete', $driver)->assertHasNoTableActionErrors();

        $this->assertNull(User::find($driver->id));
        $this->assertNull($order->fresh()->delivery_man_id);
    }

    public function test_money_left_in_wallet_debt_or_pending_withdrawal_blocks_deletion(): void
    {
        $withMoney = $this->makeUser('driver');
        \Modules\Driver\Services\DriverWalletService::adjust($withMoney->id, 50000, 'credit', 'x', 'order_1_rain');

        $withDebt = $this->makeUser('driver');
        DB::table('driver_debts')->insert(['driver_id' => $withDebt->id, 'debt_type' => 'weekly', 'status' => 'overdue', 'amount_due' => 100000,
            'amount_paid' => 0, 'note' => 'Phí tuần', 'created_at' => now(), 'updated_at' => now()]);

        $withWithdraw = $this->makeUser('driver');
        DB::table('withdraw_requests')->insert(['driver_id' => $withWithdraw->id, 'amount' => 200000, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now()]);

        foreach ([$withMoney, $withDebt, $withWithdraw] as $driver) {
            Livewire::test(ListDrivers::class)->callTableAction('delete', $driver);
            $this->assertNotNull(User::find($driver->id), 'không được xóa khi còn tiền / nợ / yêu cầu rút');
        }

        $blockers = app(\App\Services\DriverAccountService::class)->deleteBlockers($withMoney);
        $this->assertSame(['ví còn 50.000₫'], $blockers);
    }

    public function test_paid_debts_and_a_zero_wallet_do_not_block_deletion(): void
    {
        $driver = $this->makeUser('driver');
        \Modules\Driver\Services\DriverWalletService::adjust($driver->id, 50000, 'credit', 'x', 'order_2_rain');
        \Modules\Driver\Services\DriverWalletService::adjust($driver->id, 50000, 'debit', 'x', 'withdraw_hold_2');
        DB::table('driver_debts')->insert(['driver_id' => $driver->id, 'debt_type' => 'weekly', 'status' => 'paid', 'amount_due' => 100000,
            'amount_paid' => 100000, 'note' => 'Phí tuần', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([], app(\App\Services\DriverAccountService::class)->deleteBlockers($driver));
    }
}
