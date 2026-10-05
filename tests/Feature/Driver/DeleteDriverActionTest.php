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

        // Tài xế đã có đơn thì nút Xóa bị ẩn: chỉ được khóa để giữ lịch sử.
        Livewire::test(ListDrivers::class)
            ->assertTableActionHidden('delete', $driver);

        $this->assertNotNull(User::find($driver->id));
    }

    public function test_driver_with_only_completed_orders_cannot_be_deleted_either(): void
    {
        $driver = $this->makeUser('driver');
        Order::create([
            'code' => 'DEL'.uniqid(), 'status' => 'completed', 'delivery_man_id' => $driver->id,
            'pickup_address' => 'a', 'delivery_address' => 'b', 'service_type' => 'delivery',
        ]);

        Livewire::test(ListDrivers::class)->assertTableActionHidden('delete', $driver);

        $this->assertNotNull(User::find($driver->id));
    }
}
