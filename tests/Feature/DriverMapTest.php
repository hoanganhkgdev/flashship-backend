<?php

namespace Tests\Feature;

use App\Filament\Pages\DriverMapPage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

class DriverMapTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Map city', 'slug' => 'map-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, array $extra = []): User
    {
        return User::create($extra + [
            'name' => ucfirst($type).' map', 'email' => 'mp-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function order(array $o = []): Order
    {
        return Order::create($o + [
            'code' => 'MP'.uniqid(), 'status' => 'pending', 'city_id' => $this->city->id, 'service_type' => 'delivery', 'platform' => 'call_center',
            'created_by' => $this->admin->id, 'pickup_address' => '1 Lê Lợi', 'delivery_address' => '2 Trần Phú', 'pickup_phone' => '0911222333',
            'shipping_fee' => 20000, 'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
        ]);
    }

    public function test_driver_meta_carries_system_online_flag_links_and_only_this_city(): void
    {
        $mine = $this->user('driver', ['is_online' => true, 'name' => 'Tài xế khu mình']);
        $other = $this->user('driver', ['is_online' => true, 'city_id' => City::create(['name' => 'Khác', 'slug' => 'k-'.uniqid(), 'is_active' => true])->id]);
        $order = $this->order(['status' => 'assigned', 'delivery_man_id' => $mine->id]);

        $page = Livewire::test(DriverMapPage::class)->assertSuccessful();
        $meta = $page->get('driversMeta');

        $this->assertArrayHasKey($mine->id, $meta);
        $this->assertArrayNotHasKey($other->id, $meta, 'không lộ tài xế khu vực khác');
        $this->assertTrue($meta[$mine->id]['is_online'], 'cờ online của hệ thống để phát hiện mất kết nối');
        $this->assertTrue($meta[$mine->id]['busy']);
        $this->assertStringEndsWith('/drivers/'.$mine->id, $meta[$mine->id]['url']);
        $this->assertSame($order->code, $meta[$mine->id]['active_order_code']);
        $this->assertStringContainsString((string) $order->id, $meta[$mine->id]['order_url']);
    }

    public function test_pending_orders_are_scoped_oldest_first_and_flag_stopped_ones(): void
    {
        $old = $this->order(['order_note' => 'Gọi trước', 'cancel_reason' => 'no_driver']);
        DB::table('orders')->where('id', $old->id)->update(['created_at' => now()->subMinutes(30)]);
        $new = $this->order();
        $noCoords = $this->order(['pickup_lat' => null, 'pickup_lng' => null]);
        $done = $this->order(['status' => 'completed']);
        $elsewhere = $this->order(['city_id' => City::create(['name' => 'Khác 2', 'slug' => 'k2-'.uniqid(), 'is_active' => true])->id]);

        $orders = Livewire::test(DriverMapPage::class)->get('ordersMeta');

        $this->assertSame([$old->id, $new->id], array_column($orders, 'id'));
        $this->assertTrue($orders[0]['stopped']);
        $this->assertFalse($orders[1]['stopped']);
        $this->assertSame('Gọi trước', $orders[0]['note']);
        $this->assertGreaterThanOrEqual(30, $orders[0]['minutes']);
        $ids = array_column($orders, 'id');
        foreach ([$noCoords, $done, $elsewhere] as $o) {
            $this->assertNotContains($o->id, $ids);
        }
    }

    public function test_assign_action_prefills_the_chosen_driver_and_old_url_redirects(): void
    {
        $driver = $this->user('driver', ['is_online' => true]);
        $order = $this->order();

        Livewire::test(DriverMapPage::class)
            ->mountAction('assign', ['order' => $order->id, 'driver' => $driver->id])
            ->assertActionDataSet(['driver_id' => $driver->id]);

        $this->get('/admin/'.$this->city->id.'/driver-map-page')->assertRedirect('/admin/'.$this->city->id.'/ban-do-tai-xe');
        $this->assertStringEndsWith('/ban-do-tai-xe', DriverMapPage::getUrl());
    }

    public function test_eligible_drivers_exclude_those_the_assign_dialog_would_reject(): void
    {
        $ok = $this->user('driver', ['is_online' => true]);
        $suspended = $this->user('driver', ['is_online' => true]);
        DB::table('users')->where('id', $suspended->id)->update(['score_suspended_until' => now()->addDay()]);
        $offlineInDb = $this->user('driver', ['is_online' => false]);
        $order = $this->order();

        $page = Livewire::test(DriverMapPage::class);
        $ids = $page->instance()->eligibleDriverIds($order->id);

        $this->assertContains($ok->id, $ids);
        $this->assertNotContains($suspended->id, $ids);
        $this->assertNotContains($offlineInDb->id, $ids);
        $this->assertSame([], $page->instance()->eligibleDriverIds(0));

        // Chọn tài xế bị loại thì ô tài xế để trống thay vì hiện mã số
        $page->mountAction('assign', ['order' => $order->id, 'driver' => $suspended->id])
            ->assertActionDataSet(['driver_id' => null]);
    }
}
