<?php

namespace Tests\Feature;

use App\Services\DriverBankService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverDebt;
use Modules\Driver\Models\WithdrawRequest;
use Tests\TestCase;

/** API ví phía tài xế: đổi ngân hàng, công nợ chưa trả, yêu cầu rút, thông tin ví. */
class DriverWalletApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $driver;

    private string $bankCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $city = City::create(['name' => 'Wallet', 'slug' => 'wallet-'.uniqid(), 'is_active' => true]);
        $this->driver = User::create([
            'name' => 'Nguyen Van A', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'secret-123', 'user_type' => 'driver', 'status' => 1, 'city_id' => $city->id,
        ]);
        $this->bankCode = 'T'.substr(uniqid(), -6);
        DB::table('bank_lists')->insert(['code' => $this->bankCode, 'name' => 'Ngân hàng Test', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($this->driver);
    }

    private function changeBank(string $account, array $extra = [])
    {
        return $this->postJson('/api/driver/profile/bank', [
            'bank_code' => $this->bankCode, 'account_number' => $account, 'account_name' => 'nguyen van a',
        ] + $extra);
    }

    public function test_wrong_password_blocks_bank_change(): void
    {
        $this->changeBank('111222333', ['password' => 'sai-mat-khau'])
            ->assertStatus(422)->assertJsonPath('code', 'wrong_password');
        $this->assertDatabaseMissing('banks', ['user_id' => $this->driver->id]);
    }

    public function test_bank_change_is_logged_and_flagged_on_withdraw(): void
    {
        $this->changeBank('111222333', ['password' => 'secret-123'])->assertOk();
        $this->changeBank('999888777', ['password' => 'secret-123'])->assertOk();
        $this->changeBank('999888777')->assertOk(); // bản app cũ, không đổi gì → không ghi thêm

        $logs = DriverBankService::history($this->driver->id);
        $this->assertSame(['changed', 'created'], $logs->pluck('action')->all());
        $this->assertStringContainsString('••••2333 · NGUYEN VAN A → Ngân hàng Test ••••8777', $logs->first()->detail);

        $req = WithdrawRequest::create(['driver_id' => $this->driver->id, 'amount' => 100000, 'status' => 'pending']);
        $this->assertNotNull(DriverBankService::changedBefore($req));
    }

    public function test_unpaid_debts_include_old_overdue_beyond_first_page(): void
    {
        $old = DriverDebt::create(['driver_id' => $this->driver->id, 'amount_due' => 50000, 'amount_paid' => 0, 'status' => 'overdue']);
        DB::table('driver_debts')->where('id', $old->id)->update(['created_at' => now()->subMonths(3)]);
        foreach (range(1, 25) as $i) {
            DriverDebt::create(['driver_id' => $this->driver->id, 'amount_due' => 1000, 'amount_paid' => 1000, 'status' => 'paid']);
        }

        $this->assertNotContains($old->id, collect($this->getJson('/api/debts')->json('data'))->pluck('id'));
        $this->assertSame([$old->id], collect($this->getJson('/api/debts?unpaid=1')->json('data'))->pluck('id')->all());
    }

    public function test_withdraw_requests_show_status_and_reason_without_internal_fields(): void
    {
        WithdrawRequest::create([
            'driver_id' => $this->driver->id, 'amount' => 200000, 'status' => 'rejected', 'account_number' => '0123456789',
            'reject_reason' => 'bad_bank', 'admin_note' => 'Sai thông tin tài khoản nhận: sai số', 'payout_id' => 'secret', 'processed_by' => $this->driver->id,
        ]);

        $row = $this->getJson('/api/wallet/withdraw/requests')->assertOk()->json('data.0');
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('Sai thông tin tài khoản nhận: sai số', $row['reject_reason']);
        $this->assertSame('••••6789', $row['account_number']);
        $this->assertArrayNotHasKey('payout_id', $row);
        $this->assertArrayNotHasKey('processed_by', $row);
    }

    public function test_wallet_includes_bank(): void
    {
        $this->changeBank('111222333', ['password' => 'secret-123']);
        $this->getJson('/api/wallet')->assertOk()
            ->assertJsonPath('data.bank.account_number', '111222333')
            ->assertJsonPath('data.bank.account_name', 'NGUYEN VAN A');
    }
}
