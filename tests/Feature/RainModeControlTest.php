<?php

namespace Tests\Feature;

use App\Livewire\RainModeControl;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RainModeControlTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Rain control city', 'slug' => 'rain-control-'.uniqid(), 'is_active' => true]);
        $this->actingAs($this->user('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $role, int $status = 1): User
    {
        return User::create([
            'name' => 'Rain '.$role, 'email' => 'rain-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $role, 'status' => $status, 'city_id' => $this->city->id,
        ]);
    }

    public static function permittedRoles(): array
    {
        return [['admin'], ['subadmin'], ['city_manager'], ['call_center']];
    }

    #[DataProvider('permittedRoles')]
    public function test_authorized_roles_can_enable_and_disable_rain_for_the_current_city(string $role): void
    {
        $actor = $this->user($role);
        $this->actingAs($actor);
        $control = Livewire::test(RainModeControl::class)->assertSee('Chế độ trời mưa')->assertSee($this->city->name);
        $control->call('toggleCity', $this->city->id);
        $this->assertTrue($this->city->refresh()->is_rain_mode);
        $this->assertSame($actor->id, (int) $this->city->rain_mode_by);
        $this->assertNotNull($this->city->rain_mode_started_at);

        $control->call('toggleCity', $this->city->id);
        $this->assertFalse($this->city->refresh()->is_rain_mode);
        $this->assertNull($this->city->rain_mode_started_at);
        $this->assertNull($this->city->rain_mode_by);
    }

    public static function deniedRoles(): array
    {
        return [['viewer'], ['accountant'], ['driver']];
    }

    #[DataProvider('deniedRoles')]
    public function test_other_roles_cannot_see_or_invoke_rain_control(string $role): void
    {
        $this->actingAs($this->user($role));
        $control = Livewire::test(RainModeControl::class)->assertDontSee('Chế độ trời mưa');
        $this->assertFalse($control->instance()->canManage());
        $this->assertCount(0, $control->instance()->getCitiesProperty());
        $control->call('toggleCity', $this->city->id);
        $this->assertFalse($this->city->refresh()->is_rain_mode);
    }

    public function test_call_center_cannot_toggle_a_city_outside_the_current_tenant(): void
    {
        $this->actingAs($this->user('call_center'));
        $other = City::create(['name' => 'Other rain city', 'slug' => 'rain-other-'.uniqid(), 'is_active' => true]);
        Livewire::test(RainModeControl::class)->assertDontSee($other->name)->call('toggleCity', $other->id);
        $this->assertFalse($other->refresh()->is_rain_mode);
        $this->assertFalse($this->city->refresh()->is_rain_mode);
    }

    public function test_call_center_cannot_use_a_tenant_the_account_cannot_access(): void
    {
        $this->actingAs($this->user('call_center'));
        $other = City::create(['name' => 'Forbidden rain city', 'slug' => 'rain-forbidden-'.uniqid(), 'is_active' => true]);
        Filament::setTenant($other);
        $control = Livewire::test(RainModeControl::class)->assertDontSee($other->name);
        $this->assertCount(0, $control->instance()->getCitiesProperty());
        $control->call('toggleCity', $other->id);
        $this->assertFalse($other->refresh()->is_rain_mode);
    }

    public function test_locked_call_center_cannot_toggle_rain(): void
    {
        $this->actingAs($this->user('call_center', 2));
        Livewire::test(RainModeControl::class)->assertDontSee('Chế độ trời mưa')->call('toggleCity', $this->city->id);
        $this->assertFalse($this->city->refresh()->is_rain_mode);
    }

    public function test_no_tenant_does_not_allow_toggling_a_city(): void
    {
        $this->actingAs($this->user('call_center'));
        Filament::setTenant(null);
        $control = Livewire::test(RainModeControl::class);
        $this->assertCount(0, $control->instance()->getCitiesProperty());
        $control->call('toggleCity', $this->city->id);
        $this->assertFalse($this->city->refresh()->is_rain_mode);
    }
}
