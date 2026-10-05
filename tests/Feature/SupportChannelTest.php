<?php

namespace Tests\Feature;

use App\Filament\Resources\SupportConfigResource\Pages\CreateSupportConfig;
use App\Filament\Resources\SupportConfigResource\Pages\EditSupportConfig;
use App\Filament\Resources\SupportConfigResource\Pages\ListSupportConfigs;
use App\Services\SupportChannelService as S;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Modules\Admin\Models\SupportConfig;
use Modules\Core\Models\City;
use Modules\Core\Models\ConfigLog;
use Modules\Core\Models\User;
use Tests\TestCase;

class SupportChannelTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private City $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        SupportConfig::query()->delete(); // bảng thử sạch để so sánh chính xác
        $this->city = City::create(['name' => 'Support city', 'slug' => 'support-city-'.uniqid(), 'is_active' => true]);
        $this->other = City::create(['name' => 'Other city', 'slug' => 'other-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, ?City $city = null): User
    {
        return User::create([
            'name' => ucfirst($type).' support', 'email' => 'sp-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => ($city ?? $this->city)->id,
        ]);
    }

    private function channel(array $o = []): SupportConfig
    {
        return SupportConfig::create($o + ['title' => 'Kênh', 'type' => 'phone', 'value' => '0901234567', 'audience' => 'all', 'priority' => 0, 'is_active' => true]);
    }

    public function test_normalization_and_links_per_type(): void
    {
        $this->assertSame(['0901234567', null], S::normalize('phone', '+84 901 234 567'));
        $this->assertNotNull(S::normalize('phone', 'abc')[1]);
        $this->assertSame(['https://facebook.com/flashship', null], S::normalize('facebook', 'flashship'));
        $this->assertSame(['https://zalo.me/flashship', null], S::normalize('zalo', 'zalo.me/flashship'));
        $this->assertNotNull(S::normalize('zalo', 'https://example.com/x')[1]);
        $this->assertSame(['https://flashship.vn', null], S::normalize('website', 'flashship.vn'));
        $this->assertNotNull(S::normalize('email', 'khong-phai-email')[1]);

        $this->assertSame('tel:0901234567', S::link('phone', '0901234567'));
        $this->assertSame('https://zalo.me/0901234567', S::link('zalo', '0901234567'));
        $this->assertSame('mailto:a@b.vn', S::link('email', 'A@B.vn'));
        $this->assertSame('https://facebook.com/flashship', S::link('facebook', 'flashship'));
    }

    public function test_each_app_only_gets_its_audience_and_city(): void
    {
        $this->channel(['title' => 'Chung', 'audience' => 'all']);
        $this->channel(['title' => 'Chỉ tài xế', 'audience' => 'driver', 'value' => '0911111111']);
        $this->channel(['title' => 'Chỉ khách', 'audience' => 'customer', 'value' => '0922222222']);
        $this->channel(['title' => 'Khu khác', 'audience' => 'all', 'city_id' => $this->other->id, 'value' => '0933333333']);
        $this->channel(['title' => 'Đã ẩn', 'is_active' => false, 'value' => '0944444444']);

        $titles = fn (string $aud, City $c) => S::forApp($aud, $c->id)->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Chung', 'Chỉ khách'], $titles('customer', $this->city));
        $this->assertEqualsCanonicalizing(['Chung', 'Chỉ tài xế'], $titles('driver', $this->city));
        $this->assertSame(['Chung'], $titles('shop', $this->city));
        $this->assertEqualsCanonicalizing(['Chung', 'Chỉ khách', 'Khu khác'], $titles('customer', $this->other));
    }

    public function test_api_endpoints_return_link_and_filter_correctly(): void
    {
        $this->channel(['title' => 'Hotline', 'value' => '0901234567', 'priority' => 2]);
        $this->channel(['title' => 'Zalo tài xế', 'type' => 'zalo', 'value' => '0988888888', 'audience' => 'driver', 'priority' => 1]);

        Sanctum::actingAs($this->user('customer'));
        $r = $this->getJson('/api/customer/support')->assertOk();
        $this->assertSame(['Hotline'], collect($r->json('data'))->pluck('title')->all());
        $this->assertSame('tel:0901234567', $r->json('data.0.link'));
        $this->assertArrayHasKey('subtitle', $r->json('data.0'), 'trường cũ giữ nguyên');

        Sanctum::actingAs($this->user('shop'));
        $this->assertSame(['Hotline'], collect($this->getJson('/api/shop/support')->assertOk()->json('data'))->pluck('title')->all());

        // Công khai: mặc định là đối tượng khách hàng, lọc khu vực
        $pub = $this->getJson('/api/support-configs?city_id='.$this->city->id)->assertOk();
        $this->assertSame(['Hotline'], collect($pub->json('data'))->pluck('title')->all());
        $this->assertSame(['Zalo tài xế', 'Hotline'], collect($this->getJson('/api/support-configs?audience=driver')->json('data'))->pluck('title')->all(), 'đúng thứ tự ưu tiên');
    }

    public function test_flags_for_invalid_nolink_and_duplicates(): void
    {
        $a = $this->channel(['title' => 'A', 'value' => '0901234567']);
        $b = $this->channel(['title' => 'B', 'value' => '0901234567']);
        $bad = $this->channel(['title' => 'Hỏng', 'type' => 'phone', 'value' => 'abc']);
        $other = $this->channel(['title' => 'Khác', 'type' => 'other', 'value' => 'Gọi lễ tân']);

        $all = SupportConfig::all();
        $keys = fn (SupportConfig $c) => array_column(S::flags($c, $all), 'key');
        $this->assertContains('duplicate', $keys($a));
        $this->assertContains('duplicate', $keys($b));
        $this->assertContains('invalid', $keys($bad));
        $this->assertContains('nolink', $keys($other));
        $this->assertSame(4, S::summary()['shop']);
    }

    public function test_form_validates_normalizes_and_logs(): void
    {
        Livewire::test(CreateSupportConfig::class)
            ->fillForm(['title' => 'Hotline', 'type' => 'phone', 'value' => 'chữ', 'audience' => 'all', 'is_active' => true])
            ->call('create')->assertHasFormErrors(['value']);

        Livewire::test(CreateSupportConfig::class)
            ->fillForm(['title' => 'Fanpage', 'type' => 'facebook', 'value' => 'flashship', 'audience' => 'customer', 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        $c = SupportConfig::where('title', 'Fanpage')->firstOrFail();
        $this->assertSame('https://facebook.com/flashship', $c->value);
        $this->assertSame('created', ConfigLog::where('entity', 'support_config')->where('entity_id', $c->id)->first()->action);

        Livewire::test(EditSupportConfig::class, ['record' => $c->id])
            ->fillForm(['audience' => 'driver', 'subtitle' => 'Nhóm tài xế'])->call('save')->assertHasNoFormErrors();
        $detail = ConfigLog::where('entity_id', $c->id)->where('action', 'updated')->first()->detail;
        $this->assertStringContainsString('Đối tượng: customer → driver', $detail);
    }

    public function test_page_renders_and_toggle_is_logged(): void
    {
        $c = $this->channel(['title' => 'Hotline']);
        Livewire::test(ListSupportConfigs::class)->assertSuccessful()->assertCanSeeTableRecords([$c]);
        Livewire::test(ListSupportConfigs::class)->callTableAction('toggle', $c)->assertHasNoTableActionErrors();
        $this->assertFalse($c->fresh()->is_active);
        $this->assertSame('deactivated', ConfigLog::where('entity', 'support_config')->where('entity_id', $c->id)->first()->action);
    }

    public function test_web_support_page_uses_configured_channels_with_fallback(): void
    {
        $this->get('/support')->assertOk()->assertSee('1900 xxxx');

        $this->channel(['title' => 'Hotline thật', 'value' => '0977000111']);
        $this->get('/support')->assertOk()->assertSee('0977000111')->assertSee('support@flashship.vn')->assertDontSee('1900 xxxx');
    }
}
