<?php

namespace Tests\Feature;

use App\Filament\Pages\CallCenterPage;
use App\Filament\Pages\DriverMapPage;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Support\OrderListPresenter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\ServiceType;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderTimeline;
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
        // Sửa địa chỉ gõ tay thì EditOrder geocode: trả một toạ độ cố định thay vì gọi Google thật.
        Http::fake(['maps.googleapis.com/maps/api/geocode/*' => fn ($request) => str_contains(urldecode($request->url()), 'không tồn tại')
            ? Http::response(['status' => 'ZERO_RESULTS', 'results' => []])
            : Http::response(['status' => 'OK', 'results' => [['formatted_address' => 'x', 'geometry' => ['location' => ['lat' => 10.5, 'lng' => 105.5]]]]])]);
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
        $this->assertFalse(Route::has('filament.admin.resources.orders.delete'));
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
        $this->assertContains('Đơn lên danh sách đơn mới', $texts);
        $this->assertContains("Gửi đơn cho {$driver->name}", $texts);
        $this->assertContains("{$driver->name} từ chối đơn", $texts);
        $this->assertStringContainsString('Khách hủy', end($texts));
    }

    public function test_pages_render(): void
    {
        $o = $this->order();
        Livewire::test(ListOrders::class)->assertSuccessful()->assertSee('Đơn hôm nay')->assertCanSeeTableRecords([$o]);
        Livewire::test(ViewOrder::class, ['record' => $o->id])->assertSuccessful();
        $map = Livewire::test(DriverMapPage::class)->assertSuccessful();
        $this->assertSame([$o->code], collect($map->get('ordersMeta'))->pluck('code')->all(), 'đơn chờ có toạ độ hiện lên bản đồ');
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
        $base = ['pickup_address' => '1 Lê Lợi', 'shipping_fee' => 20000];

        // Mua hộ không còn ô SĐT khách: chỉ cần hàng cần mua (SĐT, nếu có, ghi trong ghi chú)
        $shopping = Livewire::test(CallCenterPage::class)->call('selectService', 'shopping')->set('pickupLat', 10.0)->set('pickupLng', 105.0)
            ->set('data', $base + ['shopping_note' => 'Trà sữa, gọi 0909 111 222'])->call('placeOrder');
        $this->assertSame([], $shopping->get('fieldErrors'));
        $this->assertNotNull($shopping->get('resultOrderCode'));

        // Cùng SĐT + địa chỉ với một đơn đã có: không cảnh báo trùng, đặt bình thường
        $this->order(['pickup_phone' => '0911222333', 'pickup_address' => '1 Lê Lợi']);
        $page = Livewire::test(CallCenterPage::class)->set('pickupLat', 10.0)->set('pickupLng', 105.0)
            ->set('data', $base + ['order_note' => 'Khách 0911 222 333'])->call('placeOrder');
        $this->assertNotNull($page->get('resultOrderCode'));
        $this->assertSame(3, Order::where('city_id', $this->city->id)->count(), 'đơn Mua hộ + đơn mẫu + đơn vừa đặt');

        // Phí 0₫ (chưa biết, tài xế báo lại sau) đặt được, không cần lý do (sau mỗi lần đặt form được làm sạch nên chọn lại điểm)
        $page->set('pickupLat', 10.0)->set('pickupLng', 105.0)
            ->set('data', ['shipping_fee' => 0, 'pickup_address' => '5 Hai Bà Trưng'])->call('placeOrder');
        $this->assertSame([], $page->get('fieldErrors'));
        $this->assertNotNull($page->get('resultOrderCode'));
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

    public function test_fee_edits_follow_call_center_rules_and_reject_typos(): void
    {
        $order = $this->order(['status' => 'processing']);
        $edit = fn (array $data) => Livewire::test(EditOrder::class, ['record' => $order->id])->fillForm($data)->call('save');

        $edit(['shipping_fee' => 25])->assertHasErrors(['data.shipping_fee']);
        $edit(['shipping_fee' => -5000])->assertHasErrors(['data.shipping_fee']);
        $edit(['shipping_fee' => 300001])->assertHasErrors(['data.shipping_fee']);
        $this->assertSame(20000, (int) $order->fresh()->shipping_fee, 'phí sai không được lưu');

        $edit(['shipping_fee' => 0])->assertHasNoErrors();
        $this->assertSame(0, (int) $order->fresh()->shipping_fee, 'được để 0 khi chưa biết phí');
    }

    public function test_night_surcharge_is_added_when_a_night_order_gets_its_fee_later(): void
    {
        $this->travelTo(now()->setTime(23, 30));
        $night = \Modules\Core\Services\OperationalSettings::nightSurcharge($this->city->id);
        $this->assertGreaterThan(0, $night);
        $order = $this->order(['status' => 'processing', 'shipping_fee' => 0, 'night_surcharge' => 0]);
        $this->travelTo(now()->addHours(10)); // tài xế báo phí vào ban ngày, vẫn tính theo giờ tạo đơn

        Livewire::test(EditOrder::class, ['record' => $order->id])->fillForm(['shipping_fee' => 30000])->call('save')->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(30000 + $night, (int) $order->shipping_fee);
        $this->assertSame($night, (int) $order->night_surcharge);

        // Mở lại form: ô hiện phí gốc, lưu lại không cộng thêm lần nữa.
        $form = Livewire::test(EditOrder::class, ['record' => $order->id]);
        $this->assertSame(30000, (int) $form->get('data.shipping_fee'));
        $form->call('save');
        $this->assertSame(30000 + $night, (int) $order->fresh()->shipping_fee);
    }

    public function test_an_order_that_already_had_a_fee_does_not_gain_a_night_surcharge(): void
    {
        $this->travelTo(now()->setTime(23, 30));
        $order = $this->order(['status' => 'processing', 'shipping_fee' => 20000, 'night_surcharge' => 0]);

        Livewire::test(EditOrder::class, ['record' => $order->id])->fillForm(['shipping_fee' => 25000])->call('save')->assertHasNoErrors();

        $this->assertSame(25000, (int) $order->fresh()->shipping_fee);
    }

    public function test_address_edits_move_the_coordinates_too(): void
    {
        $order = $this->order(['status' => 'assigned', 'delivery_lat' => 10.1, 'delivery_lng' => 105.1]);

        // Chọn trên bản đồ: toạ độ gửi kèm được lưu.
        Livewire::test(EditOrder::class, ['record' => $order->id])
            ->fillForm(['pickup_address' => 'Chợ mới', 'pickup_lat' => 10.25, 'pickup_lng' => 105.25])->call('save')->assertHasNoErrors();
        $order->refresh();
        $this->assertSame([10.25, 105.25], [(float) $order->pickup_lat, (float) $order->pickup_lng]);

        // Gõ tay: toạ độ cũ không được giữ, server tự geocode.
        Livewire::test(EditOrder::class, ['record' => $order->id])->fillForm(['delivery_address' => '9 Nguyễn Huệ'])->call('save')->assertHasNoErrors();
        $order->refresh();
        $this->assertSame([10.5, 105.5], [(float) $order->delivery_lat, (float) $order->delivery_lng]);

        // Sửa thứ khác: toạ độ giữ nguyên.
        Livewire::test(EditOrder::class, ['record' => $order->id])->fillForm(['order_note' => 'Gọi trước'])->call('save');
        $this->assertSame([10.25, 105.25], [(float) $order->fresh()->pickup_lat, (float) $order->fresh()->pickup_lng]);
    }

    public function test_unresolvable_typed_address_is_rejected(): void
    {
        $order = $this->order(['status' => 'assigned']);

        Livewire::test(EditOrder::class, ['record' => $order->id])->fillForm(['delivery_address' => 'đâu đó không tồn tại xyz'])->call('save')
            ->assertHasErrors(['data.delivery_address']);
        $this->assertSame('2 Trần Phú', $order->fresh()->delivery_address);
    }

    public function test_cancelled_order_page_shows_the_reason_in_vietnamese(): void
    {
        $order = $this->order(['status' => 'cancelled', 'cancel_reason' => 'wrong_order', 'cancel_note' => 'khách đặt nhầm']);

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertSee(OrderTimeline::cancelReasonLabel('wrong_order'))->assertDontSee('wrong_order');
    }

    public function test_csv_export_neutralises_spreadsheet_formulas(): void
    {
        $this->order(['status' => 'completed', 'completed_at' => now(), 'pickup_address' => '=HYPERLINK("http://evil","x")']);

        $page = Livewire::test(ListOrders::class)->set('activeTab', 'completed');
        ob_start();
        $page->instance()->exportCsv()->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }
}
