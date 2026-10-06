<?php

namespace Tests\Feature;

use App\Filament\Pages\AppVersionSettingsPage;
use App\Filament\Pages\OperationalSettingsPage;
use App\Filament\Pages\ZaloTokenSettings;
use App\Filament\Resources\AdminUserResource\Pages\EditAdminUser;
use App\Filament\Resources\AdminUserResource\Pages\ListAdminUsers;
use App\Filament\Resources\BankListResource\Pages\CreateBankList;
use App\Filament\Resources\BankListResource\Pages\ListBankLists;
use App\Filament\Resources\LegalPageResource\Pages\EditLegalPage;
use App\Filament\Resources\LegalPageResource\Pages\ListLegalPages;
use App\Models\AppVersionSetting;
use App\Services\AdminAccountService;
use App\Services\AppVersionPolicy;
use App\Services\BankCodeService;
use App\Services\LegalPageService;
use App\Services\ZaloTokenService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Admin\Models\Page;
use Modules\Core\Models\City;
use Modules\Core\Models\ConfigLog;
use Modules\Core\Models\User;
use Tests\TestCase;

class SystemGroupTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'System city', 'slug' => 'system-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, int $status = 1): User
    {
        return User::create([
            'name' => ucfirst($type).' sys', 'email' => 'sy-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => $status, 'city_id' => $this->city->id,
        ]);
    }

    // ── Phiên bản app ─────────────────────────────────────────────────────────

    public function test_version_policy_detects_min_above_latest_and_syncs_legacy_field(): void
    {
        $this->assertGreaterThan(0, AppVersionPolicy::compare('10.0.4', '10.0.3'));
        $this->assertSame(0, AppVersionPolicy::compare('10.0.4+104', '10.0.4'));
        $this->assertArrayHasKey('android_latest', AppVersionPolicy::conflicts('10.0.4', '10.0.3', '10.0.4'));
        $this->assertSame([], AppVersionPolicy::conflicts('10.0.2', '10.0.2', '10.0.3'));
        $this->assertSame('10.0.12', AppVersionPolicy::legacyLatest('10.0.9', '10.0.12'), 'so sánh theo số, không theo chuỗi');
    }

    public function test_version_page_rejects_min_above_latest_and_logs_a_valid_save(): void
    {
        $base = [
            'customer_min_version' => '1.0.0', 'customer_android_latest_version' => '1.2.0', 'customer_ios_latest_version' => '1.2.0', 'customer_force_update' => false,
            'driver_min_version' => '1.0.0', 'driver_android_latest_version' => '1.0.0', 'driver_ios_latest_version' => '1.0.0', 'driver_force_update' => false,
            'shop_min_version' => '1.0.0', 'shop_android_latest_version' => '1.0.0', 'shop_ios_latest_version' => '1.0.0', 'shop_force_update' => false,
        ];

        Livewire::test(AppVersionSettingsPage::class)
            ->set('data', ['customer_min_version' => '9.0.0'] + $base)->call('save')->assertHasErrors();

        Livewire::test(AppVersionSettingsPage::class)->set('data', $base)->call('save')->assertHasNoErrors();
        $this->assertSame('1.2.0', AppVersionSetting::forPlatform('customer')->latest_version);

        Livewire::test(AppVersionSettingsPage::class)
            ->set('data', ['customer_min_version' => '1.1.0'] + $base)->call('save')->assertHasNoErrors();
        $this->assertStringContainsString('Khách hàng · Tối thiểu: 1.0.0 → 1.1.0', ConfigLog::where('entity', 'app_version')->latest('id')->first()->detail);
    }

    // ── Zalo token ────────────────────────────────────────────────────────────

    public function test_zalo_tokens_are_encrypted_single_row_and_never_prefilled(): void
    {
        DB::table('zalo_tokens')->delete();
        ZaloTokenService::store('ACCESS-TOKEN-1234567890', 'REFRESH-TOKEN-1234567890', 3600);
        ZaloTokenService::store('ACCESS-TOKEN-NEW-0987654321', 'REFRESH-TOKEN-NEW-0987654321', 3600);

        $this->assertSame(1, DB::table('zalo_tokens')->count(), 'chỉ giữ một bản ghi');
        $raw = DB::table('zalo_tokens')->first();
        $this->assertStringStartsWith('enc:v1:', $raw->access_token);
        $this->assertStringNotContainsString('ACCESS-TOKEN-NEW', $raw->access_token);
        $this->assertSame('ACCESS-TOKEN-NEW-0987654321', ZaloTokenService::row()->access_token);
        $this->assertSame('plain-legacy', ZaloTokenService::decode('plain-legacy'), 'chấp nhận token cũ chưa mã hóa');

        $page = Livewire::test(ZaloTokenSettings::class);
        $page->assertSet('access_token', null)->assertSet('refresh_token', null);
        $this->assertStringNotContainsString('ACCESS-TOKEN-NEW', $page->html());
    }

    public function test_zalo_page_is_for_full_admin_only(): void
    {
        $this->actingAs($this->user('subadmin'));
        $this->assertFalse(ZaloTokenSettings::canAccess());
        $this->actingAs($this->admin);
        $this->assertTrue(ZaloTokenSettings::canAccess());
    }

    // ── Ngân hàng ─────────────────────────────────────────────────────────────

    public function test_bank_code_must_be_a_six_digit_bin_and_legacy_accounts_are_matched(): void
    {
        Livewire::test(CreateBankList::class)
            ->fillForm(['code' => 'VCB', 'name' => 'Vietcombank thử', 'is_active' => true])->call('create')->assertHasFormErrors(['code']);
        Livewire::test(CreateBankList::class)
            ->fillForm(['code' => '970436', 'name' => 'Vietcombank thử', 'is_active' => true])->call('create')->assertHasNoFormErrors();

        $driver = $this->user('driver');
        DB::table('bank_lists')->updateOrInsert(['code' => '970422'], ['name' => 'MBBank', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $good = $this->user('driver');
        DB::table('banks')->insert([
            ['user_id' => $driver->id, 'bank_code' => 'MBBANK', 'bank_name' => 'MB Bank', 'account_number' => '1', 'account_name' => 'A', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $good->id, 'bank_code' => '970422', 'bank_name' => 'MBBank', 'account_number' => '2', 'account_name' => 'B', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $dry = BankCodeService::fixLegacy(false);
        $this->assertSame('970422', $dry['fixed']->firstWhere(fn ($f) => $f['account']->user_id === $driver->id)['bank']->code);
        $this->assertSame('MBBANK', DB::table('banks')->where('user_id', $driver->id)->value('bank_code'), 'chạy thử không ghi');

        BankCodeService::fixLegacy(true);
        $this->assertSame('970422', DB::table('banks')->where('user_id', $driver->id)->value('bank_code'));
    }

    public function test_bank_in_use_cannot_be_deleted_and_toggle_is_logged(): void
    {
        $bank = \Modules\Driver\Models\BankList::updateOrCreate(['code' => '970499'], ['name' => 'Ngân hàng thử', 'is_active' => true]);
        $driver = $this->user('driver');
        DB::table('banks')->insert(['user_id' => $driver->id, 'bank_code' => '970499', 'bank_name' => 'Ngân hàng thử', 'account_number' => '1', 'account_name' => 'A', 'created_at' => now(), 'updated_at' => now()]);

        Livewire::test(ListBankLists::class)->callTableAction('delete', $bank);
        $this->assertNotNull(\Modules\Driver\Models\BankList::find($bank->id));

        Livewire::test(ListBankLists::class)->callTableAction('toggle', $bank);
        $this->assertFalse($bank->fresh()->is_active);
        $this->assertSame('deactivated', ConfigLog::where('entity', 'bank')->where('entity_id', $bank->id)->first()->action);
    }

    // ── Quản trị viên ─────────────────────────────────────────────────────────

    public function test_admin_cannot_lock_delete_or_demote_themselves_or_the_last_admin(): void
    {
        User::whereIn('user_type', ['admin'])->where('id', '<>', $this->admin->id)->update(['status' => 2]);

        $this->assertFalse(AdminAccountService::setLocked($this->admin, true, 'thử khóa', $this->admin)['ok']);
        $this->assertFalse(AdminAccountService::delete($this->admin, $this->admin)['ok']);

        $other = $this->user('admin');
        User::whereKey($this->admin->id)->update(['status' => 2]); // chỉ còn $other là admin hoạt động
        $this->assertStringContainsString('cuối cùng', AdminAccountService::blocker($other->fresh(), $this->admin->fresh(), 'lock'));
    }

    public function test_lock_unlock_and_password_reset_are_logged(): void
    {
        $sub = $this->user('subadmin');

        $this->assertFalse(AdminAccountService::setLocked($sub, true, 'x', $this->admin)['ok'], 'cần lý do');
        $this->assertTrue(AdminAccountService::setLocked($sub, true, 'Nghỉ việc', $this->admin)['ok']);
        $this->assertSame(2, (int) $sub->fresh()->status);
        $this->assertTrue(AdminAccountService::setLocked($sub->fresh(), false, 'Quay lại', $this->admin)['ok']);

        $this->assertFalse(AdminAccountService::resetPassword($sub, 'short', $this->admin)['ok']);
        $this->assertTrue(AdminAccountService::resetPassword($sub, 'mat-khau-moi-123', $this->admin)['ok']);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('mat-khau-moi-123', $sub->fresh()->password));

        $actions = ConfigLog::where('entity', 'admin_user')->where('entity_id', $sub->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['locked', 'unlocked', 'password_reset'], $actions);
    }

    public function test_account_with_history_cannot_be_deleted_but_a_fresh_one_can(): void
    {
        $fresh = $this->user('subadmin');
        $this->assertTrue(AdminAccountService::delete($fresh, $this->admin)['ok']);
        $this->assertNull(User::find($fresh->id));

        $used = $this->user('subadmin');
        DB::table('driver_leave_requests')->insert(['driver_id' => $this->user('driver')->id, 'leave_date' => today(), 'note' => 'x', 'created_by' => $used->id, 'created_at' => now(), 'updated_at' => now()]);
        $res = AdminAccountService::delete($used, $this->admin);
        $this->assertFalse($res['ok']);
        $this->assertNotNull(User::find($used->id));
    }

    public function test_admin_list_renders_when_an_admin_has_logged_in_before(): void
    {
        // Trước đây last_login_at không có cast datetime nên cột "Đăng nhập gần nhất" gọi ->format() trên chuỗi và trả lỗi 500
        DB::table('users')->where('id', $this->admin->id)->update(['last_login_at' => now()->subHour()]);

        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $this->admin->fresh()->last_login_at);
        Livewire::test(ListAdminUsers::class)->assertSuccessful()->assertCanSeeTableRecords([$this->admin]);
    }

    public function test_admin_pages_render_and_login_stamp_is_recorded(): void
    {
        Livewire::test(ListAdminUsers::class)->assertSuccessful()->assertCanSeeTableRecords([$this->admin]);
        Livewire::test(EditAdminUser::class, ['record' => $this->admin->id])->assertSuccessful();

        $this->assertNull($this->admin->fresh()->last_login_at);
        event(new \Illuminate\Auth\Events\Login('web', $this->admin, false));
        $this->assertNotNull($this->admin->fresh()->last_login_at);
    }

    // ── Trang pháp lý ─────────────────────────────────────────────────────────

    public function test_legal_edit_keeps_versions_and_restore_is_reversible(): void
    {
        $page = Page::updateOrCreate(['slug' => 'privacy-policy'], ['title' => 'Chính sách', 'content' => '<p>Bản 1</p>', 'is_active' => true]);

        Livewire::test(EditLegalPage::class, ['record' => $page->id])
            ->fillForm(['title' => 'Chính sách', 'content' => '<p>Bản 2</p>'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('privacy-policy', $page->fresh()->slug, 'slug không đổi được');
        $v = $page->versions()->first();
        $this->assertSame('<p>Bản 1</p>', $v->content);
        $this->assertSame($this->admin->id, (int) $v->performed_by);

        // Lưu lại không đổi gì thì không sinh phiên bản mới
        Livewire::test(EditLegalPage::class, ['record' => $page->id])->fillForm(['content' => '<p>Bản 2</p>'])->call('save');
        $this->assertSame(1, $page->versions()->count());

        LegalPageService::restore($v, $this->admin->id);
        $this->assertSame('<p>Bản 1</p>', $page->fresh()->content);
        $this->assertSame(2, $page->versions()->count(), 'bản 2 được giữ lại trước khi khôi phục');
    }

    public function test_public_web_pages_follow_the_database_with_static_fallback(): void
    {
        Page::where('slug', 'privacy-policy')->delete();
        $this->get('/privacy')->assertOk()->assertSee('Chính sách Quyền riêng tư');

        Page::create(['slug' => 'privacy-policy', 'title' => 'Chính sách mới', 'content' => '<p>Nội dung quản trị đã sửa</p>', 'is_active' => true]);
        $this->get('/privacy')->assertOk()->assertSee('Nội dung quản trị đã sửa')->assertSee('Chính sách mới');

        Livewire::test(ListLegalPages::class)->assertSuccessful();
    }

    // ── Cấu hình vận hành ─────────────────────────────────────────────────────

    public function test_operational_save_writes_a_history_row_with_old_and_new_values(): void
    {
        $page = Livewire::test(OperationalSettingsPage::class);
        $data = $page->get('data');
        $data['dispatch_max_road_distance_km'] = 6;
        $page->set('data', $data)->call('save')->assertHasNoErrors();

        $log = ConfigLog::where('entity', 'operational')->where('entity_id', $this->city->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Khoảng cách phát tối đa: 4 → 6', $log->detail);
        $this->assertSame($this->admin->id, (int) $log->performed_by);
    }

    // ── Tài khoản của tôi ─────────────────────────────────────────────────────

    public function test_my_account_changes_name_and_password_only_with_the_current_password(): void
    {
        DB::table('users')->where('id', $this->admin->id)->update(['password' => \Illuminate\Support\Facades\Hash::make('mat-khau-cu-123')]);
        $this->actingAs($this->admin->fresh()); // phiên đăng nhập phải thấy mật khẩu vừa đặt

        Livewire::test(\App\Filament\Pages\MyAccountPage::class)
            ->fillForm(['name' => 'Tên mới', 'email' => $this->admin->email, 'current_password' => 'sai-mat-khau', 'new_password' => 'mat-khau-moi-456', 'new_password_confirmation' => 'mat-khau-moi-456'])
            ->call('save')->assertHasFormErrors(['current_password']);
        $this->assertSame('Admin sys', $this->admin->fresh()->name);

        Livewire::test(\App\Filament\Pages\MyAccountPage::class)
            ->fillForm(['name' => 'Tên mới', 'email' => $this->admin->email, 'current_password' => 'mat-khau-cu-123', 'new_password' => 'mat-khau-moi-456', 'new_password_confirmation' => 'mat-khau-moi-456'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Tên mới', $this->admin->fresh()->name);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('mat-khau-moi-456', $this->admin->fresh()->password));
        $this->assertContains('password_reset', ConfigLog::where('entity', 'admin_user')->where('entity_id', $this->admin->id)->pluck('action')->all());
    }
}
