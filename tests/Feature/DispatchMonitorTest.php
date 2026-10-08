<?php

namespace Tests\Feature;

use App\Filament\Pages\DispatchMonitorPage;
use App\Services\DispatchMonitorReport;
use App\Services\DriverSupplyService;
use App\Support\OrderListPresenter;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

class DispatchMonitorTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $admin;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->now = Carbon::parse('2026-10-05 15:30:00');
        $this->city = City::create(['name' => 'Dispatch city', 'slug' => 'dispatch-city-'.uniqid(), 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, array $extra = []): User
    {
        return User::create($extra + [
            'name' => ucfirst($type).' disp', 'email' => 'dp-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function order(array $o = []): Order
    {
        $started = $o['dispatch_started_at'] ?? $this->now->copy()->subMinute();
        unset($o['dispatch_started_at']); // không nằm trong $fillable: hệ thống gán khi bắt đầu phát

        $order = Order::create($o + [
            'code' => 'DP'.uniqid(), 'status' => 'pending', 'city_id' => $this->city->id, 'service_type' => 'delivery', 'platform' => 'call_center',
            'created_by' => $this->admin->id, 'pickup_address' => '1 Lê Lợi', 'delivery_address' => '2 Trần Phú', 'pickup_phone' => '0911222333',
            'shipping_fee' => 20000, 'dispatch_attempts' => 1,
        ]);
        DB::table('orders')->where('id', $order->id)->update(['dispatch_started_at' => $started]);

        return $order->fresh();
    }

    private function log(Order $o, User $driver, string $result, ?Carbon $offered = null, ?int $respondAfter = null): void
    {
        $offered ??= $this->now->copy()->subMinutes(5);
        DB::table('order_dispatch_logs')->insert([
            'order_id' => $o->id, 'driver_id' => $driver->id, 'offered_at' => $offered, 'result' => $result,
            'responded_at' => $respondAfter === null ? null : $offered->copy()->addSeconds($respondAfter),
            'created_at' => $offered, 'updated_at' => $offered,
        ]);
    }

    public function test_call_center_can_access_dispatch_monitor_in_its_city(): void
    {
        $callCenter = $this->user('call_center');
        $this->actingAs($callCenter);

        $this->assertTrue(DispatchMonitorPage::canAccess());
        Livewire::test(DispatchMonitorPage::class)->assertSuccessful();
    }

    public function test_orders_split_into_attention_and_active_with_longest_wait_first(): void
    {
        $fresh = $this->order(['dispatch_started_at' => $this->now->copy()->subSeconds(30)]);
        $old = $this->order(['dispatch_started_at' => $this->now->copy()->subMinutes(10)]);
        $stopped = $this->order(['dispatch_started_at' => $this->now->copy()->subMinutes(4), 'cancel_reason' => 'no_driver', 'order_note' => 'Gọi trước khi tới']);
        $overdue = $this->order(['dispatch_started_at' => $this->now->copy()->subMinutes(20)]);
        $assigned = $this->order(['status' => 'assigned', 'delivery_man_id' => $this->user('driver')->id]);
        $otherCity = $this->order(['city_id' => City::create(['name' => 'Khác', 'slug' => 'khac-'.uniqid(), 'is_active' => true])->id]);

        $r = (new DispatchMonitorReport)->orders($this->city->id, 15 * 60, $this->now);

        $this->assertSame([$overdue->id, $stopped->id], array_column($r['attention'], 'id'), 'đơn kẹt: chờ lâu nhất trước');
        $this->assertSame('timeout', $r['attention'][0]['kind']);
        $this->assertSame('no_driver', $r['attention'][1]['kind']);
        $this->assertSame('Gọi trước khi tới', $r['attention'][1]['note']);
        $this->assertSame('0911 222 333', OrderListPresenter::phone($r['attention'][1]['phone']));
        $this->assertSame([$old->id, $fresh->id], array_column($r['active'], 'id'), 'đơn đang phát: chờ lâu nhất trước, không cắt đơn cũ');
        $ids = array_merge(array_column($r['active'], 'id'), array_column($r['attention'], 'id'));
        $this->assertNotContains($assigned->id, $ids);
        $this->assertNotContains($otherCity->id, $ids);
    }

    public function test_yesterday_is_compared_up_to_the_same_time_of_day(): void
    {
        // Hôm nay trước 15:30: 1 đơn có tài xế. Hôm qua trước 15:30: 1 đơn. Hôm qua sau 15:30: 3 đơn không được tính.
        $driver = $this->user('driver');
        $a = $this->order(['dispatch_started_at' => $this->now->copy()->setTime(9, 0), 'status' => 'assigned', 'delivery_man_id' => $driver->id]);
        $this->log($a, $driver, 'accepted', $this->now->copy()->setTime(9, 0), 45);
        $this->order(['dispatch_started_at' => $this->now->copy()->subDay()->setTime(10, 0)]);
        foreach ([16, 17, 18] as $h) {
            $this->order(['dispatch_started_at' => $this->now->copy()->subDay()->setTime($h, 0)]);
        }

        $s = (new DispatchMonitorReport)->todayVsYesterday($this->city->id, $this->now);

        $this->assertSame(1, $s['today']['total']);
        $this->assertSame(100, $s['today']['accept_rate']);
        $this->assertSame(45, $s['today']['avg_wait_secs']);
        $this->assertSame(45, $s['today']['max_wait_secs']);
        $this->assertSame(1, $s['yesterday']['total'], 'chỉ tính đến 15:30 hôm qua');
    }

    public function test_top_decliners_need_enough_offers_and_rank_by_decline_rate(): void
    {
        $bad = $this->user('driver', ['name' => 'Hay từ chối']);
        $ok = $this->user('driver', ['name' => 'Ổn']);
        $few = $this->user('driver', ['name' => 'Ít lượt']);

        foreach (['declined', 'declined', 'expired', 'accepted'] as $res) {
            $this->log($this->order(), $bad, $res);
        }
        foreach (['declined', 'accepted', 'accepted'] as $res) {
            $this->log($this->order(), $ok, $res);
        }
        foreach (['declined', 'declined'] as $res) {
            $this->log($this->order(), $few, $res);
        }

        $top = (new DispatchMonitorReport)->topDecliners($this->city->id, $this->now);

        $this->assertSame(['Hay từ chối', 'Ổn'], array_column($top, 'name'), 'người dưới 3 lượt không được xếp hạng');
        $this->assertSame(50, $top[0]['decline_rate']);
        $this->assertSame(1, $top[0]['expired']);
    }

    public function test_recent_offers_filter_and_hourly_buckets(): void
    {
        $driver = $this->user('driver');
        $this->log($this->order(), $driver, 'declined');
        $this->log($this->order(), $driver, 'accepted', null, 12);
        $report = new DispatchMonitorReport;

        $this->assertCount(2, $report->recentOffers($this->city->id));
        $declined = $report->recentOffers($this->city->id, 'declined');
        $this->assertCount(1, $declined);
        $this->assertSame($driver->id, $declined[0]['driver_id']);

        $this->order(['dispatch_started_at' => $this->now->copy()->setTime(14, 10), 'delivery_man_id' => $driver->id, 'status' => 'assigned']);
        $this->order(['dispatch_started_at' => $this->now->copy()->setTime(14, 40)]);
        $h = collect($report->hourly($this->city->id, $this->now))->keyBy('hour');
        $this->assertCount(24, $report->hourly($this->city->id, $this->now));
        $this->assertSame(2, $h[14]['total']);
        $this->assertSame(1, $h[14]['accepted']);
    }

    public function test_supply_snapshot_keeps_counts_and_lists_the_drivers_per_group(): void
    {
        $this->user('driver', ['is_online' => true, 'name' => 'Online im lặng']);
        $svc = app(DriverSupplyService::class);

        $with = $svc->snapshotWithDrivers($this->city->id);
        $plain = $svc->snapshot($this->city->id);

        $this->assertArrayNotHasKey('drivers', $plain);
        $this->assertSame($with['online'], $plain['online']);
        $this->assertSame(1, $plain['online']);
        $this->assertSame(1, $plain['dead'], 'không có vị trí Firebase => mất kết nối');
        $this->assertSame('Online im lặng', $with['drivers']['dead'][0]['name']);
        $this->assertNull($with['drivers']['dead'][0]['seen_ago']);
    }

    public function test_page_renders_attention_and_supply_lists_and_old_url_redirects(): void
    {
        $this->order(['dispatch_started_at' => now()->subMinutes(2), 'cancel_reason' => 'no_driver', 'order_note' => 'Nhớ lấy hóa đơn']);
        $this->user('driver', ['is_online' => true, 'name' => 'Tài xế mất sóng']);

        Livewire::test(DispatchMonitorPage::class)
            ->assertSuccessful()
            ->assertSee('Cần xử lý ngay')
            ->assertSee('Hệ thống đã dừng tìm')
            ->assertSee('Nhớ lấy hóa đơn')
            ->assertDontSee('Tài xế mất sóng')
            ->call('toggleSegment', 'dead')
            ->assertSee('Tài xế mất sóng')
            ->call('setOfferFilter', 'declined')
            ->assertSee('Không có lượt offer nào')
            ->call('setOfferFilter', 'bậy')
            ->assertSet('offerFilter', 'all');

        $this->get('/admin/'.$this->city->id.'/dispatch-monitor-page')->assertRedirect('/admin/'.$this->city->id.'/theo-doi-phat-don');
    }
}
