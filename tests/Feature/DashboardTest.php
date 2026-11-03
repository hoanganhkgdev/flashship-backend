<?php

namespace Tests\Feature;

use App\Filament\Widgets\LiveOrdersWidget;
use App\Filament\Widgets\LowRatingsWidget;
use App\Filament\Widgets\OnlineDriversWidget;
use App\Filament\Widgets\OpsAlertsWidget;
use App\Filament\Widgets\OrdersByHourWidget;
use App\Filament\Widgets\OrderSourcesWidget;
use App\Filament\Widgets\ServiceMixWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\TopDriversWidget;
use App\Services\DriverSupplyService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->travelTo(now()->setTime(14, 30));
        $this->city = City::create(['name' => 'Dash city', 'slug' => 'dash-'.uniqid(), 'is_active' => true]);
        $this->driver = $this->user('driver');
        $this->actingAs($this->user('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, array $extra = []): User
    {
        return User::create($extra + [
            'name' => ucfirst($type).' dash', 'email' => 'dash-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function order(array $attrs = []): Order
    {
        $created = $attrs['created_at'] ?? now()->subHour();
        unset($attrs['created_at']);
        $o = Order::create($attrs + [
            'code' => 'DB'.uniqid(), 'status' => 'completed', 'city_id' => $this->city->id, 'service_type' => 'delivery',
            'platform' => 'shop_app', 'payment_method' => 'cod', 'pickup_address' => 'a', 'delivery_address' => 'b',
            'shipping_fee' => 20000, 'bonus_fee' => 0, 'night_surcharge' => 0, 'discount_amount' => 0,
            'delivery_man_id' => $this->driver->id, 'completed_at' => now()->subMinutes(10),
        ]);
        DB::table('orders')->where('id', $o->id)->update(['created_at' => $created]);

        return $o->fresh();
    }

    public function test_operational_widgets_refresh_themselves(): void
    {
        // Widget thường của Filament bỏ qua $pollingInterval: phải có wire:poll trong view.
        foreach ([StatsOverviewWidget::class, OpsAlertsWidget::class, LiveOrdersWidget::class, OnlineDriversWidget::class] as $w) {
            Livewire::test($w)->assertSuccessful()->assertSeeHtml('wire:poll.15s');
        }
        foreach ([ServiceMixWidget::class, OrderSourcesWidget::class, TopDriversWidget::class, LowRatingsWidget::class] as $w) {
            Livewire::test($w)->assertSuccessful()->assertSeeHtml('wire:poll.60s');
        }
    }

    public function test_revenue_matches_the_revenue_report_definition(): void
    {
        $this->order(['shipping_fee' => 30000]);
        // Freeship do tổng đài bật: khách trả 0₫, công ty trả cho tài xế → không phải doanh thu.
        $this->order(['shipping_fee' => 25000, 'is_freeship' => true, 'platform' => 'call_center']);

        Livewire::test(StatsOverviewWidget::class)->assertViewHas('revenue', '30.000₫')->assertViewHas('completedOrders', 2);

        $rows = collect(Livewire::test(ServiceMixWidget::class)->viewData('rows'));
        $this->assertSame('30.000₫', $rows->firstWhere('count', 2)['note']);
    }

    public function test_kpis_count_only_this_city_and_compare_with_the_same_time_yesterday(): void
    {
        $other = City::create(['name' => 'Khác', 'slug' => 'k-'.uniqid(), 'is_active' => true]);
        $this->order();
        $this->order(['city_id' => $other->id]);
        $this->order(['status' => 'pending', 'delivery_man_id' => null, 'completed_at' => null]);
        // Hôm qua: một đơn trước giờ này (được so) và một đơn sau giờ này (không được so).
        $this->order(['created_at' => now()->subDay()->subHour(), 'completed_at' => now()->subDay()->subMinutes(30)]);
        $this->order(['created_at' => now()->subDay()->addHour(), 'completed_at' => now()->subDay()->addHours(2)]);

        Livewire::test(StatsOverviewWidget::class)
            ->assertViewHas('totalOrders', 2)
            ->assertViewHas('completedOrders', 1)
            ->assertViewHas('waitingOrders', 1)
            ->assertViewHas('trends', fn ($t) => $t['total']['text'] === '▲ 100%' && $t['completed']['text'] === '= 0%');
    }

    public function test_hourly_chart_counts_completions_by_completion_hour(): void
    {
        // Đặt 9:50, giao xong 10:20: cột "Đơn đặt" ở 9h, cột "Giao xong" ở 10h.
        $this->order(['created_at' => now()->setTime(9, 50), 'completed_at' => now()->setTime(10, 20)]);

        $data = (fn () => $this->getData())->call(new OrdersByHourWidget);
        $this->assertSame(1, $data['datasets'][0]['data'][9]);
        $this->assertSame(0, $data['datasets'][1]['data'][9]);
        $this->assertSame(1, $data['datasets'][1]['data'][10]);
    }

    public function test_long_waits_are_readable(): void
    {
        $this->order(['status' => 'pending', 'delivery_man_id' => null, 'completed_at' => null, 'created_at' => now()->subDays(3)]);

        Livewire::test(LiveOrdersWidget::class)->assertSee('Chờ tài xế · 3 ngày')->assertDontSee('4320p');
        Livewire::test(OpsAlertsWidget::class)->assertSee('Đơn cũ nhất đã chờ 3 ngày');
    }

    public function test_accountants_do_not_get_links_to_the_driver_map(): void
    {
        $map = \App\Filament\Pages\DriverMapPage::getUrl();
        Livewire::test(OpsAlertsWidget::class)->assertSeeHtml('href="'.$map.'"');

        $this->actingAs($this->user('accountant'));
        Livewire::test(OpsAlertsWidget::class)->assertSuccessful()->assertSee('Tài xế mất kết nối')->assertDontSeeHtml('href="'.$map.'"');
        Livewire::test(OnlineDriversWidget::class)->assertDontSeeHtml('href="'.$map.'"');
        Livewire::test(StatsOverviewWidget::class)->assertDontSeeHtml('href="'.$map.'"');
    }

    public function test_top_drivers_show_earnings_with_the_driver_earnings_formula(): void
    {
        $this->order(['shipping_fee' => 20000, 'bonus_fee' => 5000]);
        $this->order(['shipping_fee' => 25000, 'is_freeship' => true]); // công ty trả nguyên 25.000₫

        $top = Livewire::test(TopDriversWidget::class)->instance()->getDrivers()->first();
        $this->assertSame(2, $top['orders']);
        $this->assertSame('50.000₫', $top['fee']);
    }

    public function test_without_a_city_nothing_is_shown(): void
    {
        $this->order();
        $this->assertSame(0, app(DriverSupplyService::class)->snapshot(0)['online']);
        $this->user('driver', ['is_online' => true]);
        $this->assertSame(0, app(DriverSupplyService::class)->snapshot(null)['online'], 'không bao giờ là mọi khu vực');
        $this->assertSame(0, \App\Services\DriverRatingService::pendingCount(null));
    }
}
