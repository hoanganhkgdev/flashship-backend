<?php

namespace Tests\Feature;

use App\Filament\Pages\DriverEarningsPage;
use App\Filament\Pages\RevenueReportPage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

class DriverEarningsTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->travelTo(now()->setTime(12, 0));
        $this->city = City::create(['name' => 'DE city', 'slug' => 'de-city-'.uniqid(), 'is_active' => true]);
        $this->actingAs($this->user('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, array $extra = []): User
    {
        return User::create($extra + [
            'name' => ucfirst($type).' de', 'email' => 'de-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function completed(User $driver, array $attrs = []): Order
    {
        return Order::create($attrs + [
            'code' => 'DE'.uniqid(), 'status' => 'completed', 'city_id' => $this->city->id, 'service_type' => 'delivery',
            'platform' => 'shop_app', 'payment_method' => 'cod', 'pickup_address' => 'a', 'delivery_address' => 'b',
            'shipping_fee' => 0, 'bonus_fee' => 0, 'night_surcharge' => 0, 'discount_amount' => 0,
            'delivery_man_id' => $driver->id, 'completed_at' => now()->subHour(),
        ]);
    }

    private function page()
    {
        return Livewire::test(DriverEarningsPage::class)->call('setPeriod', 'this_month');
    }

    private function row(array $report, User $driver): ?array
    {
        return collect($report['drivers'])->firstWhere('id', $driver->id);
    }

    public function test_every_component_comes_from_the_completed_orders_and_matches_the_revenue_report(): void
    {
        $driver = $this->user('driver');
        $this->completed($driver, ['shipping_fee' => 25000, 'night_surcharge' => 5000]); // phụ phí đêm đã nằm trong phí
        $this->completed($driver, ['shipping_fee' => 20000, 'bonus_fee' => 10000]);
        $this->completed($driver, ['platform' => 'call_center', 'shipping_fee' => 30000, 'is_freeship' => true]);
        $this->completed($driver, ['shipping_fee' => 15000, 'discount_amount' => 5000, 'voucher_code' => 'SALE5']);
        $this->completed($driver, ['shipping_fee' => 10000, 'rain_bonus_eligible' => true, 'rain_bonus_amount' => 3000]);
        $this->completed($driver, ['shipping_fee' => 10000, 'rain_bonus_eligible' => false, 'rain_bonus_amount' => 3000]);

        $row = $this->row($this->page()->instance()->getReportData(), $driver);

        $this->assertSame(6, $row['orders']);
        $this->assertSame(25000 + 20000 + 30000 + 15000 + 10000 + 10000, $row['shipping'], 'freeship tính trong phí giao, đúng một lần');
        $this->assertSame(10000, $row['surcharge'], 'cột Bonus = bonus_fee');
        $this->assertSame(5000, $row['discount']);
        $this->assertSame(3000, $row['rain'], 'chỉ đơn đủ điều kiện');
        $this->assertSame(128000, $row['total']);

        $revenue = Livewire::test(RevenueReportPage::class)->call('setPeriod', 'this_month')->instance()->getReportData();
        $this->assertSame($revenue['summary']['driver_total'], $row['total'], 'cùng con số "tài xế nhận" với trang Doanh thu');
    }

    public function test_earnings_follow_the_order_city_not_the_drivers_current_city(): void
    {
        $other = City::create(['name' => 'Khu mới', 'slug' => 'km-'.uniqid(), 'is_active' => true]);
        $moved = $this->user('driver', ['name' => 'Đã chuyển đi']);
        $this->completed($moved, ['shipping_fee' => 40000]);
        $this->completed($moved, ['shipping_fee' => 99000, 'city_id' => $other->id]); // đơn ở khu vực khác
        $moved->update(['city_id' => $other->id]);

        $report = $this->page()->instance()->getReportData();

        $this->assertSame(40000, $this->row($report, $moved)['total'], 'đơn đã làm ở đây vẫn thuộc khu vực này, đơn khu khác không tính');
        $this->assertSame(40000, $report['totals']['total']);
    }

    public function test_wallet_credits_no_longer_shift_money_into_another_period(): void
    {
        $driver = $this->user('driver');
        $order = $this->completed($driver, ['shipping_fee' => 15000, 'discount_amount' => 5000, 'voucher_code' => 'X', 'completed_at' => now()->subMonthNoOverflow()->endOfMonth()]);
        $wallet = DB::table('driver_wallets')->insertGetId(['driver_id' => $driver->id, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('driver_wallet_transactions')->insert(['wallet_id' => $wallet, 'type' => 'credit', 'amount' => 5000, 'reference' => "order_{$order->id}_discount",
            'description' => 'Bù giảm giá', 'created_at' => now()->startOfMonth(), 'updated_at' => now()]);

        $report = $this->page()->instance()->getReportData();

        $this->assertSame(0, $report['totals']['discount'], 'tiền bù của đơn tháng trước không rơi vào tháng này');
        $this->assertSame(0, $report['totals']['total']);
    }

    public function test_export_neutralises_formulas(): void
    {
        $driver = $this->user('driver', ['name' => '=HYPERLINK("http://evil","x")']);
        $this->completed($driver, ['shipping_fee' => 20000]);

        $page = $this->page();
        ob_start();
        $page->instance()->exportCsv()->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        // Quyền: hiện chỉ admin/kế toán vào được trang và cả hai đều có EXPORT_DATA; exportCsv() vẫn tự kiểm tra
        // (abort_unless canExport) để vai trò mới được cấp FINANCE_VIEW không tự có quyền xuất.
        $this->assertTrue($page->instance()->canExport());
    }

    public function test_previous_period_matches_each_bucket_by_date_and_long_ranges_are_capped(): void
    {
        $driver = $this->user('driver');
        $this->completed($driver, ['shipping_fee' => 40000, 'completed_at' => now()->subDays(7)]);
        $this->completed($driver, ['shipping_fee' => 10000, 'completed_at' => now()]);

        $report = Livewire::test(DriverEarningsPage::class)->call('setPeriod', 'last_7_days')->instance()->getReportData();
        $today = collect($report['chart']['bars'])->firstWhere('key', now()->toDateString());
        $this->assertSame(10000, $today['revenue']);
        $this->assertSame(40000, $today['prev_revenue']);

        $long = Livewire::test(DriverEarningsPage::class)->set('from', '0001-01-01')->set('to', now()->toDateString())->instance()->getReportData();
        $this->assertTrue($long['clamped']);
    }
}
