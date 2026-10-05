<?php

namespace Tests\Feature;

use App\Filament\Resources\WithdrawRequestResource\Pages\ListWithdrawRequests;
use App\Filament\Resources\WithdrawRequestResource\Pages\ViewWithdrawRequest;
use App\Services\WithdrawService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverWallet;
use Modules\Driver\Models\WithdrawRequest;
use Modules\Driver\Services\DriverWalletService;
use Tests\TestCase;

class WithdrawRequestTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Withdraw city', 'slug' => 'withdraw-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->driver = $this->user('driver');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, int $status = 1): User
    {
        return User::create([
            'name' => 'Nguyễn Văn An', 'email' => 'wd-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => $status, 'city_id' => $this->city->id,
        ]);
    }

    /** Tạo yêu cầu đúng như API: giữ tiền trong ví rồi tạo request pending. */
    private function request(int $amount = 200000, ?User $driver = null, string $holder = 'NGUYEN VAN AN'): WithdrawRequest
    {
        $driver ??= $this->driver;
        DriverWalletService::adjust($driver->id, $amount, 'credit', 'nạp', 'seed_'.uniqid());
        $req = WithdrawRequest::create([
            'driver_id' => $driver->id, 'amount' => $amount, 'bank_code' => '970436', 'bank_name' => 'Vietcombank',
            'account_number' => '0123456789', 'account_name' => $holder, 'status' => 'pending',
        ]);
        DriverWalletService::adjust($driver->id, $amount, 'debit', 'Yêu cầu rút tiền #'.$req->id, 'withdraw_hold_'.$req->id);

        return $req;
    }

    public function test_manual_approval_records_reference_without_touching_the_wallet(): void
    {
        $req = $this->request();
        $res = WithdrawService::approveManual($req, 'FT26100512345', 'Đã CK', $this->admin->id);

        $this->assertTrue($res['ok']);
        $req->refresh();
        $this->assertSame('approved', $req->status);
        $this->assertSame('manual', $req->payout_method);
        $this->assertSame('FT26100512345', $req->payout_reference);
        $this->assertSame($this->admin->id, (int) $req->processed_by);
        $this->assertSame(0.0, DriverWallet::where('driver_id', $this->driver->id)->first()->balance);
    }

    public function test_manual_approval_needs_a_unique_reference_and_cannot_repeat(): void
    {
        $a = $this->request();
        $b = $this->request(100000, $this->user('driver'));

        $this->assertFalse(WithdrawService::approveManual($a, 'ab', null, $this->admin->id)['ok']);
        $this->assertTrue(WithdrawService::approveManual($a, 'FT-1111', null, $this->admin->id)['ok']);
        $this->assertFalse(WithdrawService::approveManual($a, 'FT-2222', null, $this->admin->id)['ok'], 'đã xử lý rồi');
        $this->assertFalse(WithdrawService::approveManual($b, 'FT-1111', null, $this->admin->id)['ok'], 'trùng mã giao dịch');
        $this->assertSame('pending', $b->fresh()->status);
    }

    public function test_reject_refunds_once_stores_reason_and_requires_text_for_other(): void
    {
        $req = $this->request();

        $this->assertFalse(WithdrawService::reject($req, 'other', '.', 1)['ok']);
        $this->assertFalse(WithdrawService::reject($req, 'nope', null, $this->admin->id)['ok']);
        $this->assertSame('pending', $req->fresh()->status);

        $this->assertTrue(WithdrawService::reject($req, 'below_min', null, $this->admin->id)['ok']);
        $req->refresh();
        $this->assertSame('rejected', $req->status);
        $this->assertSame('below_min', $req->reject_reason);
        $this->assertStringContainsString('Dưới mức rút tối thiểu', $req->admin_note);
        $this->assertSame(200000.0, DriverWallet::where('driver_id', $this->driver->id)->first()->balance);

        $this->assertFalse(WithdrawService::reject($req, 'below_min', null, $this->admin->id)['ok']);
        $this->assertSame(200000.0, DriverWallet::where('driver_id', $this->driver->id)->first()->balance, 'không hoàn lần hai');
    }

    public function test_payos_approval_is_blocked_when_not_configured_or_driver_locked(): void
    {
        config(['services.payos_payout.client_id' => null]);
        $req = $this->request();
        $this->assertStringContainsString('chưa được cấu hình', WithdrawService::approvePayos($req, null, $this->admin->id)['message']);
        $this->assertSame('pending', $req->fresh()->status);

        config(['services.payos_payout.client_id' => 'x', 'services.payos_payout.api_key' => 'x', 'services.payos_payout.checksum_key' => 'x']);
        DB::table('users')->where('id', $this->driver->id)->update(['status' => 2]);
        $this->assertStringContainsString('tạm ngưng', WithdrawService::approvePayos($req->fresh(), null, $this->admin->id)['message']);
    }

    public function test_payos_failure_is_recorded_and_success_marks_approved(): void
    {
        config(['services.payos_payout.client_id' => 'x', 'services.payos_payout.api_key' => 'x', 'services.payos_payout.checksum_key' => 'x']);
        $req = $this->request();

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['code' => '99', 'desc' => 'Không đủ số dư'], 200)
            ->push(['code' => '00', 'data' => []], 200)]);
        $this->assertFalse(WithdrawService::approvePayos($req, null, $this->admin->id)['ok']);
        $req->refresh();
        $this->assertSame('pending', $req->status);
        $this->assertSame('Không đủ số dư', $req->last_payout_error);
        $this->assertNotNull($req->last_payout_attempt_at);

        $this->assertTrue(WithdrawService::approvePayos($req, null, $this->admin->id)['ok']);
        $req->refresh();
        $this->assertSame('approved', $req->status);
        $this->assertSame('payos', $req->payout_method);
        $this->assertSame('WD'.$req->id, $req->payout_reference);
        $this->assertNull($req->last_payout_error);
    }

    public function test_name_matching_ignores_accents_case_and_spaces(): void
    {
        $this->assertTrue(WithdrawService::nameMatches('NGUYEN VAN AN', 'Nguyễn Văn An'));
        $this->assertTrue(WithdrawService::nameMatches('BUINHATDUY', 'Bùi Nhật Duy'));
        $this->assertFalse(WithdrawService::nameMatches('TRAN THI B', 'Nguyễn Văn An'));
        $this->assertFalse(WithdrawService::nameMatches(null, 'Nguyễn Văn An'));
    }

    public function test_api_rejects_amounts_below_the_configured_minimum_with_a_clear_message(): void
    {
        $this->assertSame(100000, \Modules\Core\Services\OperationalSettings::minWithdrawAmount($this->city->id));
        DriverWalletService::adjust($this->driver->id, 500000, 'credit', 'nạp', 'seed_'.uniqid());
        \Laravel\Sanctum\Sanctum::actingAs($this->driver);

        $this->postJson('/api/wallet/withdraw', ['amount' => 60000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount')
            ->assertJsonFragment(['amount' => ['Số tiền rút tối thiểu là 100.000đ.']]);
        $this->assertSame(0, WithdrawRequest::where('driver_id', $this->driver->id)->count());
    }

    public function test_pages_render_and_reject_action_works_from_the_table(): void
    {
        $req = $this->request();

        Livewire::test(ListWithdrawRequests::class)->assertSuccessful()->assertCanSeeTableRecords([$req]);

        Livewire::test(ListWithdrawRequests::class)
            ->callTableAction('reject', $req, ['reason' => 'other', 'note' => ''])
            ->assertHasTableActionErrors(['note']);

        Livewire::test(ListWithdrawRequests::class)
            ->callTableAction('reject', $req, ['reason' => 'bad_bank'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('rejected', $req->fresh()->status);

        Livewire::test(ViewWithdrawRequest::class, ['record' => $req->id])->assertSuccessful()->assertSee('Tài khoản nhận');
    }
}
