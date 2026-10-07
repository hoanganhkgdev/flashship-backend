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

    public function test_call_center_can_cancel_processing_order_and_restore_its_voucher(): void
    {
        $o = $this->order(['status' => 'processing', 'is_freeship' => true, 'rain_bonus_eligible' => true]);

        $this->assertTrue(OrderResource::cancelOrder($o, 'customer_cancel', 'Khách hủy sau khi tài xế bấm lấy hàng'));

        $o->refresh();
        $this->assertSame('cancelled', $o->status);
        $this->assertSame('customer_cancel', $o->cancel_reason);
        $this->assertStringContainsString('đã lấy hàng', OrderTimeline::for($o)->last()['text']);
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

    public function test_call_center_accepts_missing_phone_any_fee_and_repeat_orders(): void
    {
        $page = Livewire::test(CallCenterPage::class)
            ->set('pickupLat', 10.0)->set('pickupLng', 105.0)->set('deliveryLat', 10.01)->set('deliveryLng', 105.01);

        $base = ['pickup_address' => '1 Lê Lợi', 'delivery_address' => '2 Trần Phú', 'shipping_fee' => 20000, 'fee_note' => ''];

        // Mua hộ cũng không bắt buộc SĐT (trang riêng để không đổi trạng thái của $page)
        $shopping = Livewire::test(CallCenterPage::class)->call('selectService', 'shopping')->set('pickupLat', 10.0)->set('pickupLng', 105.0)
            ->set('data', $base + ['contact_phone' => '', 'delivery_phone' => '', 'shopping_note' => 'Trà sữa'])->call('placeOrder');
        $this->assertArrayNotHasKey('contact_phone', $shopping->get('fieldErrors'));
        $this->assertNotNull($shopping->get('resultOrderCode'));

        // Cùng SĐT + địa chỉ với đơn vừa tạo: không còn cảnh báo trùng, đặt bình thường
        $this->order(['pickup_phone' => '0911222333', 'pickup_address' => '1 Lê Lợi']);
        $page->set('data', $base + ['contact_phone' => '0911 222 333', 'delivery_phone' => ''])->call('placeOrder');
        $this->assertNotNull($page->get('resultOrderCode'));
        $this->assertSame(3, Order::where('city_id', $this->city->id)->count(), 'đơn Mua hộ không SĐT + đơn mẫu + đơn vừa đặt');

        // Phí khác giá hệ thống hoặc 0₫ không còn bắt buộc lý do (sau mỗi lần đặt, form và toạ độ được làm sạch nên chọn lại điểm)
        $page->set('pickupLat', 10.0)->set('pickupLng', 105.0);
        $page->set('data', ['shipping_fee' => 0, 'pickup_address' => '5 Hai Bà Trưng', 'contact_phone' => '0988000111', 'delivery_address' => 'x', 'delivery_phone' => '', 'fee_note' => ''])->call('placeOrder');
        $this->assertArrayNotHasKey('fee_note', $page->get('fieldErrors'));
        $this->assertNotNull($page->get('resultOrderCode'));
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

        $crossAddress = $this->order([
            'pickup_address' => '421 Ngô Quyền',
            'delivery_address' => '102 Nguyễn Trung Trực',
            'pickup_phone' => '0387789978',
        ]);
        $this->assertSame([$crossAddress->id], $ids('421 102'), 'mỗi cụm có thể khớp một phần khác nhau của hành trình');

        $houseNumber = $this->order([
            'pickup_address' => '173 Nguyễn Bỉnh Khiêm',
            'delivery_address' => 'Chợ Rạch Giá',
            'pickup_phone' => '0900173999',
        ]);
        $phoneNoise = $this->order([
            'pickup_address' => '27 Lạc Hồng',
            'delivery_address' => '45 Trần Phú',
            'pickup_phone' => '0912345173',
        ]);
        $embeddedHouseNumber = $this->order([
            'pickup_address' => '1173 Nguyễn Trung Trực',
            'delivery_address' => 'Bến xe',
        ]);
        $this->assertSame([$houseNumber->id], $ids('173'), 'ba số tìm số nhà chính xác, không dò đuôi SĐT hoặc một phần số nhà dài hơn');
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

    public function test_processing_tab_lists_the_newest_orders_first(): void
    {
        $old = $this->order(['status' => 'assigned']);
        DB::table('orders')->where('id', $old->id)->update(['created_at' => now()->subHour()]);
        $new = $this->order(['status' => 'processing']);

        Livewire::test(ListOrders::class)->set('activeTab', 'processing')
            ->assertCanSeeTableRecords([$new, $old], inOrder: true);
    }

    public function test_assign_dialog_lists_unavailable_drivers_greyed_out_with_a_reason(): void
    {
        $ok = $this->user('driver');
        DB::table('users')->where('id', $ok->id)->update(['is_online' => true, 'name' => 'Sẵn sàng']);
        $off = $this->user('driver');
        DB::table('users')->where('id', $off->id)->update(['is_online' => false, 'name' => 'Đang tắt máy']);
        $order = $this->order();

        $select = OrderResource::manualAssignmentForm($order)[0];
        $options = $select->getOptions();

        $this->assertArrayHasKey($ok->id, $options);
        $this->assertStringContainsString('đang offline', $options[$off->id]);
        $this->assertTrue($select->isOptionDisabled($off->id, $options[$off->id]));
        $this->assertFalse($select->isOptionDisabled($ok->id, $options[$ok->id]));
    }

    public function test_quick_view_does_not_break_when_the_order_leaves_the_list_while_open(): void
    {
        $o = $this->order();
        $page = Livewire::test(ListOrders::class)->mountTableAction('view', $o);

        // Đơn bị gán/hủy/biến mất khỏi danh sách trong lúc tổng đài đang mở xem nhanh (danh sách tự làm mới 15s)
        DB::table('orders')->where('id', $o->id)->delete();

        $page->call('$refresh')->assertSuccessful();
    }
}
