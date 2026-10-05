<?php

namespace Tests\Feature;

use App\Filament\Pages\CallCenterPage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\ServiceType;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

class CallCenterTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Queue::fake();
        // Phát đơn thật chạm Redis/Firebase/broadcast: ở đây chỉ kiểm tra đơn được tạo đúng và được đưa vào phát
        $this->mock(\Modules\Order\Services\OrderService::class, fn ($m) => $m->shouldReceive('dispatchNewOrder')->andReturnNull());
        foreach (['delivery' => 'Lấy đồ hộ', 'shopping' => 'Mua hộ', 'topup' => 'Nạp tiền'] as $k => $l) {
            ServiceType::updateOrCreate(['key' => $k], ['label' => $l, 'sort_order' => 1, 'is_active' => true]);
        }
        $this->city = City::create(['name' => 'CC city', 'slug' => 'cc-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, array $extra = []): User
    {
        return User::create($extra + [
            'name' => ucfirst($type).' cc', 'email' => 'cc-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function page(string $service = 'delivery')
    {
        return Livewire::test(CallCenterPage::class)->call('selectService', $service)->set('pickupLat', 10.0)->set('pickupLng', 105.0);
    }

    private function data(array $o = []): array
    {
        return $o + [
            'contact_phone' => '0911222333', 'contact_name' => '', 'pickup_address' => 'Quán Cơm Cô Ba', 'delivery_address' => '', 'delivery_phone' => '',
            'shopping_note' => '', 'order_note' => '', 'cod_amount' => null, 'shipping_fee' => 15000, 'fee_note' => '',
        ];
    }

    public function test_shop_order_can_be_placed_without_the_delivery_point_or_customer_phone(): void
    {
        $page = $this->page('delivery')->set('data', $this->data(['contact_name' => 'Cơm Cô Ba', 'order_note' => 'Mang 2 phần']))->call('placeOrder');

        $this->assertSame([], $page->get('fieldErrors'));
        $this->assertNotNull($page->get('resultOrderCode'), (string) $page->get('resultError'));
        $order = Order::where('code', $page->get('resultOrderCode'))->firstOrFail();
        $this->assertSame('', (string) $order->delivery_address);
        $this->assertNull($order->delivery_lat);
        $this->assertSame('0911222333', $order->pickup_phone, 'SĐT shop là SĐT liên hệ');
        $this->assertNull($order->delivery_phone);
        $this->assertSame('Cơm Cô Ba', $order->sender_name);
        $this->assertStringStartsWith(CallCenterPage::NO_DELIVERY_NOTE, $order->order_note, 'tài xế được báo hỏi shop khi lấy hàng');
        $this->assertStringContainsString('Mang 2 phần', $order->order_note);
        $this->assertStringContainsString('chưa có điểm giao', \Modules\Order\Services\OrderTimeline::for($order)->where('type', 'created')->pluck('text')->implode(' | '));
    }

    public function test_shopping_and_topup_still_need_their_required_fields_with_inline_errors(): void
    {
        $shopping = $this->page('shopping')->set('data', $this->data(['shipping_fee' => 10000]))->call('placeOrder');
        $this->assertEqualsCanonicalizing(['delivery_address', 'shopping_note'], array_keys($shopping->get('fieldErrors')));
        $this->assertStringContainsString('2 mục', $shopping->get('resultError'));

        $topup = $this->page('topup')->set('data', $this->data(['cod_amount' => 0]))->call('placeOrder');
        $this->assertSame(['cod_amount'], array_keys($topup->get('fieldErrors')));
        $this->assertSame(0, Order::where('city_id', $this->city->id)->count(), 'lỗi thì không tạo đơn');
    }

    public function test_switching_service_keeps_the_customer_and_journey_but_drops_service_specific_fields(): void
    {
        $page = $this->page('delivery')->set('data', $this->data(['delivery_address' => 'Nhà khách', 'delivery_phone' => '0900', 'order_note' => 'x', 'shipping_fee' => 20000, 'fee_note' => 'thỏa thuận']))
            ->call('selectService', 'shopping');

        $d = $page->get('data');
        $this->assertSame('0911222333', $d['contact_phone']);
        $this->assertSame('Quán Cơm Cô Ba', $d['pickup_address']);
        $this->assertSame('Nhà khách', $d['delivery_address']);
        $this->assertSame(10.0, $page->get('pickupLat'));
        $this->assertNull($d['shipping_fee']);
        $this->assertSame('', $d['fee_note']);
        $this->assertSame('', $d['delivery_phone']);
    }

    public function test_an_ineligible_driver_is_rejected_before_any_order_is_created(): void
    {
        $suspended = $this->user('driver', ['is_online' => true]);
        DB::table('users')->where('id', $suspended->id)->update(['score_suspended_until' => now()->addDay()]);

        $page = $this->page('delivery')->set('assignedDriverId', $suspended->id)->set('data', $this->data())->call('placeOrder');

        $this->assertArrayHasKey('assignedDriverId', $page->get('fieldErrors'));
        $this->assertSame(0, Order::where('city_id', $this->city->id)->count(), 'không tạo rồi hủy đơn rác');
    }

    public function test_driver_list_only_has_assignable_drivers(): void
    {
        $ok = $this->user('driver', ['is_online' => true]);
        $suspended = $this->user('driver', ['is_online' => true]);
        DB::table('users')->where('id', $suspended->id)->update(['score_suspended_until' => now()->addDay()]);
        $this->user('driver', ['is_online' => false]);

        $page = $this->page('delivery')->call('setPickupLocation', 'Quán', 10.0, 105.0);

        $this->assertSame([$ok->id], array_column($page->get('onlineDrivers'), 'id'));
    }

    public function test_recent_orders_show_todays_call_center_orders_with_no_delivery_flag(): void
    {
        $page = $this->page('delivery')->set('data', $this->data())->call('placeOrder');
        Order::create(['code' => 'OLD'.uniqid(), 'status' => 'pending', 'city_id' => $this->city->id, 'service_type' => 'delivery', 'platform' => 'customer_app',
            'pickup_address' => 'a', 'delivery_address' => 'b', 'created_by' => $this->admin->id]);

        $recent = $page->instance()->recentOrders();

        $this->assertCount(1, $recent, 'chỉ đơn tổng đài tạo hôm nay');
        $this->assertSame($page->get('resultOrderCode'), $recent[0]['code']);
        $this->assertTrue($recent[0]['no_delivery']);
        $this->assertSame('Chưa có điểm giao', $recent[0]['delivery']);
        $this->assertSame('0911 222 333', $recent[0]['phone']);
    }

    public function test_risky_customer_is_flagged_and_old_url_redirects_with_query(): void
    {
        foreach (['cancelled', 'cancelled', 'completed'] as $st) {
            Order::create(['code' => 'R'.uniqid(), 'status' => $st, 'city_id' => $this->city->id, 'service_type' => 'delivery', 'platform' => 'call_center',
                'pickup_address' => 'a', 'delivery_address' => 'b', 'pickup_phone' => '0933444555', 'created_by' => $this->admin->id]);
        }
        $page = Livewire::test(CallCenterPage::class)->call('loadHistory', '0933 444 555');
        $this->assertTrue($page->get('customer')['risky']);

        $this->get('/admin/'.$this->city->id.'/call-center-page?reorder=1&service=delivery')
            ->assertRedirect('/admin/'.$this->city->id.'/tong-dai-dat-don?reorder=1&service=delivery');
        $this->assertStringEndsWith('/tong-dai-dat-don', CallCenterPage::getUrl());
    }

    public function test_a_dispatch_failure_after_creating_the_order_is_reported_as_created_not_failed(): void
    {
        $this->mock(\Modules\Order\Services\OrderService::class, fn ($m) => $m->shouldReceive('dispatchNewOrder')->andThrow(new \RuntimeException('Connection refused')));

        $page = $this->page('delivery')->set('data', $this->data())->call('placeOrder');

        $order = Order::where('pickup_phone', '0911222333')->firstOrFail();
        $this->assertSame($order->code, $page->get('resultOrderCode'), 'đơn đã tạo thì báo là đã tạo');
        $this->assertNull($page->get('resultError'));
        $this->assertStringContainsString('chưa phát được', $page->get('resultWarning'));
        $this->assertStringContainsString('Đừng đặt lại', $page->get('resultWarning'));
        $this->assertSame('', $page->get('data')['pickup_address'], 'form được làm sạch để khỏi đặt trùng');
    }
}
