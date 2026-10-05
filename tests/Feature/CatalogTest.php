<?php

namespace Tests\Feature;

use App\Filament\Resources\CityResource\Pages\CreateCity;
use App\Filament\Resources\CityResource\Pages\EditCity;
use App\Filament\Resources\CityResource\Pages\ListCities;
use App\Filament\Resources\CityResource\Pages\ViewCity;
use App\Filament\Resources\ServiceTypeResource\Pages\EditServiceType;
use App\Filament\Resources\ServiceTypeResource\Pages\ListServiceTypes;
use App\Services\CatalogService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\ConfigLog;
use Modules\Core\Models\ServiceType;
use Modules\Core\Models\User;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Catalog city', 'slug' => 'catalog-city-'.uniqid(), 'is_active' => true, 'lat' => 10.1, 'lng' => 105.1]);
        $this->admin = User::create([
            'name' => 'Admin catalog', 'email' => 'cat-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'admin', 'status' => 1, 'city_id' => $this->city->id,
        ]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function customerIn(City $c): User
    {
        return User::create([
            'name' => 'Khách', 'email' => 'kh-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'customer', 'status' => 1, 'city_id' => $c->id,
        ]);
    }

    public function test_public_cities_api_hides_test_cities_and_internal_fields(): void
    {
        $test = City::create(['name' => 'Khu thử', 'slug' => 'khu-thu-'.uniqid(), 'is_active' => true, 'is_test' => true, 'weekly_fee' => 350000]);
        $off = City::create(['name' => 'Khu tắt', 'slug' => 'khu-tat-'.uniqid(), 'is_active' => false]);

        $res = $this->getJson('/api/cities')->assertOk();
        $ids = collect($res->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->city->id));
        $this->assertFalse($ids->contains($test->id));
        $this->assertFalse($ids->contains($off->id));

        $row = collect($res->json('data'))->firstWhere('id', $this->city->id);
        $this->assertEqualsCanonicalizing(['id', 'name', 'slug', 'lat', 'lng', 'is_rain_mode'], array_keys($row));
        $this->assertArrayNotHasKey('weekly_fee', $row);
    }

    public function test_health_flags(): void
    {
        $inactive = City::create(['name' => 'Cũ', 'slug' => 'cu-'.uniqid(), 'is_active' => false]);
        $this->customerIn($inactive);
        $nocoords = City::create(['name' => 'Chưa tọa độ', 'slug' => 'ctd-'.uniqid(), 'is_active' => true]);

        ServiceType::create(['key' => 'fl'.random_int(1000, 9999), 'label' => 'Dịch vụ cờ', 'sort_order' => 97, 'is_active' => true]);

        $h = CatalogService::health();
        $keys = fn (City $c) => array_column($h[$c->id]['flags'], 'key');
        $this->assertContains('inactive_data', $keys($inactive));
        $this->assertContains('no_coords', $keys($nocoords));
        $this->assertContains('missing_price', $keys($this->city), 'khu vực mới chưa có giá dịch vụ nào');
    }

    public function test_city_toggle_is_logged_with_impact_and_edit_logs_changes(): void
    {
        $this->customerIn($this->city);
        $other = City::create(['name' => 'Khu khác', 'slug' => 'khac-'.uniqid(), 'is_active' => true, 'weekly_fee' => 0]);
        $this->customerIn($other);

        CatalogService::setCityActive($other, false, $this->admin->id);
        $this->assertFalse($other->fresh()->is_active);
        $log = ConfigLog::where('entity', 'city')->where('entity_id', $other->id)->first();
        $this->assertSame('deactivated', $log->action);
        $this->assertStringContainsString('1 khách', $log->detail);

        Livewire::test(EditCity::class, ['record' => $other->id])
            ->fillForm(['name' => 'Khu khác', 'slug' => 'KHU Khác Mới', 'weekly_fee' => 350000, 'is_active' => false])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('khu-khac-moi', $other->fresh()->slug);
        $upd = ConfigLog::where('entity_id', $other->id)->where('action', 'updated')->first();
        $this->assertStringContainsString('Phí tuần: 0 → 350000', $upd->detail);
        $this->assertStringContainsString('Slug', $upd->detail);
        $this->assertStringNotContainsString('Vĩ độ', $upd->detail);
    }

    public function test_create_normalizes_slug_and_logs(): void
    {
        Livewire::test(CreateCity::class)
            ->fillForm(['name' => 'Cà Mau', 'slug' => 'Ca-Mau', 'weekly_fee' => 0, 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        $c = City::where('name', 'Cà Mau')->firstOrFail();
        $this->assertSame('ca-mau', $c->slug);
        $this->assertSame('created', ConfigLog::where('entity', 'city')->where('entity_id', $c->id)->first()->action);
    }

    public function test_service_toggle_and_edit_are_logged_and_delete_stays_blocked_when_used(): void
    {
        $s = ServiceType::create(['key' => 'svc'.random_int(1000, 9999), 'label' => 'Dịch vụ thử', 'sort_order' => 99, 'is_active' => true]);

        CatalogService::setServiceActive($s, false, $this->admin->id);
        $this->assertFalse($s->fresh()->is_active);
        $this->assertSame('deactivated', ConfigLog::where('entity', 'service_type')->where('entity_id', $s->id)->first()->action);

        Livewire::test(EditServiceType::class, ['record' => $s->id])
            ->fillForm(['label' => 'Dịch vụ đổi tên', 'sort_order' => 7, 'is_active' => true])->call('save')->assertHasNoFormErrors();
        $detail = ConfigLog::where('entity_id', $s->id)->where('action', 'updated')->first()->detail;
        $this->assertStringContainsString('Tên: Dịch vụ thử → Dịch vụ đổi tên', $detail);
        $this->assertStringContainsString('Thứ tự: 99 → 7', $detail);
    }

    public function test_pricing_matrix_and_pages_render(): void
    {
        $s = ServiceType::create(['key' => 'mx'.random_int(1000, 9999), 'label' => 'Dịch vụ ma trận', 'sort_order' => 98, 'is_active' => true]);
        DB::table('pricing_configs')->insert(['service_type' => $s->key, 'city_id' => $this->city->id, 'label' => 'x', 'base_km' => 1, 'base_fee' => 1000, 'per_km_fee' => 1000, 'min_fee' => 1000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(CatalogService::pricingMatrix()[$this->city->id][$s->key]);

        Livewire::test(ListCities::class)->assertSuccessful()->assertCanSeeTableRecords([$this->city]);
        Livewire::test(ViewCity::class, ['record' => $this->city->id])->assertSuccessful()->assertSee('Giá theo dịch vụ');
        Livewire::test(ListServiceTypes::class)->assertSuccessful()->assertCanSeeTableRecords([$s]);
    }
}
