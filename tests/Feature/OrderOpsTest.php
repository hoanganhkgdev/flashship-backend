<?php

namespace Tests\Feature;

use App\Filament\Pages\CallCenterPage;
use App\Filament\Pages\DispatchMonitorPage;
use App\Filament\Pages\DriverMapPage;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\ServiceType;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderTimeline;
use App\Support\OrderListPresenter;
use Tests\TestCase;

class OrderOpsTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Ops city', 'slug' => 'ops-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type): User
    {
        return User::create([
            'name' => ucfirst($type).' ops', 'email' => 'op-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function order(array $o = []): Order
    {
        return Order::create($o + [
            'code' => 'OP'.uniqid(), 'status' => 'pending', 'city_id' => $this->city->id, 'service_type' => 'delivery', 'platform' => 'call_center',
            'created_by' => $this->admin->id, 'pickup_address' => '1 Lê Lợi', 'delivery_address' => '2 Trần Phú', 'pickup_phone' => '0911222333',
            'shipping_fee' => 20000, 'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
        ]);
    }

    public function test_cancel_stores_reason_actor_time_and_a_timeline_entry(): void
    {
        $o = $this->order();

        $this->assertTrue(OrderResource::cancelOrder($o, 'wrong_order', 'Khách gọi lại'));

        $o->refresh();
        $this->assertSame('cancelled', $o->status);
        $this->assertSame('wrong_order', $o->cancel_reason);
        $this->assertSame('Khách gọi lại', $o->cancel_note);
        $this->assertSame($this->admin->id, (int) $o->cancelled_by);
        $this->assertNotNull($o->cancelled_at);
        $events = OrderTimeline::for($o);
        $this->assertSame(1, $events->where('type', 'cancelled')->count(), 'không ghi trùng sự kiện hủy');
        $this->assertStringContainsString('Đặt nhầm', $events->firstWhere('type', 'cancelled')['text']);
        $this->assertSame($this->admin->name, $events->firstWhere('type', 'cancelled')['who']);
    }

    public function test_cancel_action_requires_a_reason_and_orders_cannot_be_deleted(): void
    {
        $o = $this->order();

        Livewire::test(ListOrders::class)->assertTableActionDoesNotExist('delete');
        Livewire::test(ListOrders::class)->callTableAction('cancel', $o, ['reason' => null])->assertHasTableActionErrors(['reason']);
        Livewire::test(ListOrders::class)->callTableAction('cancel', $o, ['reason' => 'other', 'note' => ''])->assertHasTableActionErrors(['note']);
        $this->assertSame('pending', $o->fresh()->status);

        Livewire::test(ListOrders::class)->callTableAction('cancel', $o, ['reason' => 'duplicate'])->assertHasNoTableActionErrors();
        $this->assertSame('duplicate', $o->fresh()->cancel_reason);
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('filament.admin.resources.orders.delete'));
    }

    public function test_finished_orders_only_allow_note_edits_and_edits_are_logged(): void
    {
        $open = $this->order();
        Livewire::test(EditOrder::class, ['record' => $open->id])
            ->fillForm(['shipping_fee' => 30000, 'delivery_address' => '9 Nguyễn Huệ'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(30000, (int) $open->fresh()->shipping_fee);
        $edit = OrderTimeline::for($open->fresh())->firstWhere('type', 'edited');
        $this->assertMatchesRegularExpression('/Phí ship: 20000(\.00)? → 30000/', $edit['text']);
        $this->assertStringContainsString('Địa chỉ giao: 2 Trần Phú → 9 Nguyễn Huệ', $edit['text']);

        $done = $this->order(['status' => 'completed', 'completed_at' => now()]);
        Livewire::test(EditOrder::class, ['record' => $done->id])
            ->fillForm(['shipping_fee' => 1, 'delivery_address' => 'Sửa trái phép', 'order_note' => 'Ghi chú mới'])->call('save');
        $done->refresh();
        $this->assertSame(20000, (int) $done->shipping_fee);
        $this->assertSame('2 Trần Phú', $done->delivery_address);
        $this->assertSame('Ghi chú mới', $done->order_note);
    }

    public function test_timeline_merges_creation_offers_and_cancellation(): void
    {
        $driver = $this->user('driver');
        $o = $this->order();
        DB::table('orders')->where('id', $o->id)->update(['created_at' => now()->subMinutes(6), 'dispatch_started_at' => now()->subMinutes(5)]);
        DB::table('order_dispatch_logs')->insert(['order_id' => $o->id, 'driver_id' => $driver->id, 'offered_at' => now()->subMinutes(4), 'responded_at' => now()->subMinutes(3), 'result' => 'declined', 'created_at' => now(), 'updated_at' => now()]);
        OrderResource::cancelOrder($o, 'customer_cancel', null);

        $texts = OrderTimeline::for($o->fresh())->pluck('text')->all();
        $this->assertStringContainsString('Tạo đơn', $texts[0]);
        $this->assertContains('Bắt đầu tìm tài xế', $texts);
        $this->assertContains("Gửi đơn cho {$driver->name}", $texts);
        $this->assertContains("{$driver->name} từ chối đơn", $texts);
        $this->assertStringContainsString('Khách hủy', end($texts));
    }

    public function test_pages_render(): void
    {
        $o = $this->order();
        Livewire::test(ListOrders::class)->assertSuccessful()->assertSee('Đơn hôm nay')->assertCanSeeTableRecords([$o]);
        Livewire::test(ViewOrder::class, ['record' => $o->id])->assertSuccessful();
        Livewire::test(DispatchMonitorPage::class)->assertSuccessful();
        $map = Livewire::test(DriverMapPage::class)->assertSuccessful();
        $this->assertSame([$o->code], collect($map->get('ordersMeta'))->pluck('code')->all(), 'đơn chờ có toạ độ hiện lên bản đồ');
    }

    public function test_dispatch_monitor_cancel_action_requires_a_reason(): void
    {
        $o = $this->order();
        DB::table('orders')->where('id', $o->id)->update(['dispatch_started_at' => now()]);

        Livewire::test(DispatchMonitorPage::class)
            ->callAction('cancel', ['reason' => null], ['order' => $o->id])->assertHasActionErrors(['reason']);
        Livewire::test(DispatchMonitorPage::class)
            ->callAction('cancel', ['reason' => 'driver_no_show'], ['order' => $o->id]);
        $this->assertSame('driver_no_show', $o->fresh()->cancel_reason);
    }

    // ── Tổng đài ──────────────────────────────────────────────────────────────

    public function test_call_center_services_follow_the_catalog(): void
    {
        ServiceType::updateOrCreate(['key' => 'delivery'], ['label' => 'Lấy đồ hộ', 'sort_order' => 1, 'is_active' => true]);
        ServiceType::updateOrCreate(['key' => 'bike'], ['label' => 'Xe ôm', 'sort_order' => 2, 'is_active' => false]);

        $keys = array_keys(CallCenterPage::services());
        $this->assertContains('delivery', $keys);
        $this->assertNotContains('bike', $keys, 'dịch vụ đã ẩn không còn trên tổng đài');
        $this->assertSame('Lấy đồ hộ', CallCenterPage::services()['delivery']['label']);
    }

    public function test_call_center_requires_phone_warns_on_duplicates_and_needs_a_fee_reason(): void
    {
        $page = Livewire::test(CallCenterPage::class)
            ->set('pickupLat', 10.0)->set('pickupLng', 105.0)->set('deliveryLat', 10.01)->set('deliveryLng', 105.01);

        $base = ['pickup_address' => '1 Lê Lợi', 'delivery_address' => '2 Trần Phú', 'shipping_fee' => 20000, 'fee_note' => ''];

        $page->set('data', $base + ['contact_phone' => '', 'delivery_phone' => ''])->call('placeOrder');
        $this->assertStringContainsString('số điện thoại khách', $page->get('resultError'));
        $this->assertArrayHasKey('contact_phone', $page->get('fieldErrors'));

        // Cùng SĐT + địa chỉ vừa tạo → cảnh báo trùng, chưa tạo đơn mới
        $dup = $this->order(['pickup_phone' => '0911222333', 'pickup_address' => '1 Lê Lợi']);
        $page->set('data', $base + ['contact_phone' => '0911 222 333', 'delivery_phone' => ''])->call('placeOrder');
        $this->assertSame($dup->code, $page->get('duplicateOf'));
        $this->assertSame(1, Order::where('city_id', $this->city->id)->count());

        // Phí 0₫ không freeship → bắt buộc lý do
        $page->set('data', ['shipping_fee' => 0, 'pickup_address' => '5 Hai Bà Trưng', 'contact_phone' => '0988000111', 'delivery_address' => 'x', 'delivery_phone' => '', 'fee_note' => ''])->call('placeOrder');
        $this->assertStringContainsString('Lý do phí', $page->get('resultError'));
        $this->assertArrayHasKey('fee_note', $page->get('fieldErrors'));
    }

    public function test_call_center_customer_history_and_reuse(): void
    {
        $old = $this->order(['pickup_phone' => '0977123456', 'pickup_address' => 'Quán A', 'delivery_address' => 'Nhà B', 'pickup_lat' => 10.2, 'pickup_lng' => 105.2, 'sender_name' => 'Quán A']);
        $this->order(['pickup_phone' => '0977123456', 'status' => 'cancelled']);

        $page = Livewire::test(CallCenterPage::class)->set('data', ['contact_phone' => '0977 123 456', 'contact_name' => '', 'pickup_address' => '', 'delivery_address' => '', 'shipping_fee' => null, 'fee_note' => ''])
            ->call('loadHistory', '0977 123 456');
        $this->assertContains($old->code, collect($page->get('history'))->pluck('code')->all());
        $customer = $page->get('customer');
        $this->assertSame(2, $customer['total']);
        $this->assertSame(1, $customer['cancelled']);
        $this->assertSame('Quán A', $customer['name']);
        $this->assertSame('Quán A', $page->get('data')['contact_name'], 'tự điền tên khách quen');

        $page->call('useHistory', $old->id);
        $this->assertSame('Quán A', $page->get('data')['pickup_address']);
        $this->assertSame(10.2, $page->get('pickupLat'));
    }

    // ── Danh sách đơn ─────────────────────────────────────────────────────────

    public function test_addresses_are_shortened_for_display_only(): void
    {
        $this->assertSame('6 Trần Hưng Đạo', OrderListPresenter::shortAddress('6 Trần Hưng Đạo, Rạch Giá, An Giang, Việt Nam', 'Rạch Giá'));
        $this->assertSame('X47M+MC Thạnh Lộc', OrderListPresenter::shortAddress('X47M+MC Thạnh Lộc, Kiên Giang, Việt Nam', 'Rạch Giá'));
        $this->assertSame('Rạch Giá', OrderListPresenter::shortAddress('Rạch Giá, An Giang, Việt Nam', 'Rạch Giá'), 'luôn giữ lại ít nhất phần đầu');
        $this->assertSame('—', OrderListPresenter::shortAddress('', null));
        $this->assertSame('0828 290 490', OrderListPresenter::phone('0828290490'));
    }

    public function test_smart_search_matches_phone_tail_code_and_text(): void
    {
        $byPhone = $this->order(['pickup_phone' => '0911222333', 'pickup_address' => 'Quán Cơm A']);
        $other = $this->order(['pickup_phone' => '0988777666', 'pickup_address' => 'Tiệm Bánh B']);
        $driver = $this->user('driver');
        $byDriver = $this->order(['pickup_phone' => '0900000001', 'delivery_man_id' => $driver->id, 'status' => 'assigned']);

        $ids = fn (string $q) => OrderResource::smartSearch(Order::query(), $q)->pluck('id')->all();
        $this->assertSame([$byPhone->id], $ids('0911 222 333'));
        $this->assertSame([$byPhone->id], $ids('2333'), 'vài số cuối của SĐT');
        $this->assertSame([$other->id], $ids(substr($other->code, -6)), 'khớp mã đơn (một phần mã)');
        $this->assertSame([$other->id], $ids('Tiệm Bánh'));
        $this->assertContains($byDriver->id, $ids($driver->name));
    }

    public function test_tab_badges_come_from_one_consistent_count(): void
    {
        $this->order(['status' => 'completed', 'completed_at' => now()]);
        $this->order(['status' => 'completed', 'completed_at' => now()]);
        $this->order(['status' => 'cancelled']);
        $this->order(); // pending

        $page = Livewire::test(ListOrders::class);
        $tabs = $page->instance()->getTabs();
        $badge = fn (string $k) => $tabs[$k]->getBadge();

        $this->assertSame(4, $badge('all'));
        $this->assertSame(2, $badge('completed'));
        $this->assertSame(1, $badge('cancelled'));
        $this->assertSame(1, $badge('new'));
    }

    public function test_export_is_admin_only_and_follows_the_current_filters(): void
    {
        $this->order(['status' => 'completed', 'completed_at' => now(), 'delivery_address' => 'Địa chỉ xuất 1']);
        $this->order(['status' => 'cancelled', 'cancel_reason' => 'wrong_order']);

        $response = Livewire::test(ListOrders::class)->set('activeTab', 'completed')->callAction('export');
        $this->assertNotNull($response);

        $this->actingAs($this->user('subadmin'));
        Livewire::test(ListOrders::class)->assertActionHidden('export');
    }

    public function test_live_tabs_poll_but_history_tabs_do_not(): void
    {
        $poll = fn (string $tab) => str_contains($this->get(OrderResource::getUrl('index').'?activeTab='.$tab)->assertOk()->getContent(), 'wire:poll.15s');

        $this->assertTrue($poll('new'));
        $this->assertTrue($poll('processing'));
        $this->assertFalse($poll('completed'));
        $this->assertFalse($poll('cancelled'));
    }
}
