<?php

namespace Tests\Feature;

use App\Filament\Pages\RevenueReportPage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

class RevenueReportTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->travelTo(now()->setTime(12, 0));
        $this->city = City::create(['name' => 'RR city', 'slug' => 'rr-city-'.uniqid(), 'is_active' => true]);
        $this->actingAs($this->user('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type): User
    {
        return User::create([
            'name' => ucfirst($type).' rr', 'email' => 'rr-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function completed(array $attrs = []): Order
    {
        return Order::create($attrs + [
            'code' => 'RR'.uniqid(), 'status' => 'completed', 'city_id' => $this->city->id, 'service_type' => 'delivery',
            'platform' => 'shop_app', 'payment_method' => 'cod', 'pickup_address' => 'a', 'delivery_address' => 'b',
            'shipping_fee' => 0, 'bonus_fee' => 0, 'night_surcharge' => 0, 'discount_amount' => 0, 'completed_at' => now()->subHour(),
        ]);
    }

    private function report(): array
    {
        return Livewire::test(RevenueReportPage::class)->call('setPeriod', 'this_month')->instance()->getReportData();
    }

    public function test_night_surcharge_already_in_the_fee_is_not_counted_twice(): void
    {
        // Đơn 20.000₫ đặt ban đêm: shipping_fee lưu 25.000 (đã gồm 5.000 phụ phí), night_surcharge ghi phần tách.
        $this->completed(['shipping_fee' => 25000, 'night_surcharge' => 5000]);

        $s = $this->report()['summary'];

        $this->assertSame(25000, $s['total_revenue'], 'khách trả 25.000, không phải 30.000');
        $this->assertSame(5000, $s['night_included']);
        $this->assertSame(0, $s['platform_cost']);
    }

    public function test_bonus_freeship_voucher_and_rain_are_platform_costs_not_revenue(): void
    {
        $this->completed(['shipping_fee' => 20000, 'bonus_fee' => 10000]);
        // Freeship tổng đài: khách trả 0, công ty trả nguyên phí cho tài xế.
        $this->completed(['platform' => 'call_center', 'shipping_fee' => 30000, 'is_freeship' => true]);
        // Voucher: shipping_fee đã trừ 5.000, công ty bù 5.000 cho tài xế.
        $this->completed(['shipping_fee' => 15000, 'discount_amount' => 5000, 'voucher_code' => 'SALE5']);
        $this->completed(['shipping_fee' => 10000, 'rain_bonus_eligible' => true, 'rain_bonus_amount' => 3000]);
        $this->completed(['shipping_fee' => 10000, 'rain_bonus_eligible' => false, 'rain_bonus_amount' => 3000]);

        $s = $this->report()['summary'];

        $this->assertSame(20000 + 0 + 15000 + 10000 + 10000, $s['total_revenue']);
        $this->assertSame(['bonus' => 10000, 'discount' => 5000, 'freeship' => 30000, 'rain' => 3000], $s['costs']);
        $this->assertSame(48000, $s['platform_cost']);
        $this->assertSame($s['total_revenue'] + 48000, $s['driver_total'], 'tài xế nhận = khách trả + công ty chi');
    }

    public function test_export_needs_the_export_permission(): void
    {
        $this->completed(['shipping_fee' => 20000]);

        $this->actingAs($this->user('viewer'));
        Livewire::test(RevenueReportPage::class)->call('exportCsv')->assertForbidden();

        $this->actingAs($this->user('accountant'));
        Livewire::test(RevenueReportPage::class)->call('exportCsv')->assertOk();
    }

    public function test_csv_keeps_every_order_when_completion_order_differs_from_id_order(): void
    {
        // Id tăng nhưng hoàn thành theo thứ tự ngược lại — kiểu chunkById cũ bỏ sót sau lô đầu.
        $rows = [];
        for ($i = 0; $i < 520; $i++) {
            $rows[] = ['code' => 'CSV'.$i, 'status' => 'completed', 'city_id' => $this->city->id, 'service_type' => 'delivery', 'platform' => 'shop_app',
                'payment_method' => 'cod', 'pickup_address' => 'a', 'delivery_address' => 'b', 'shipping_fee' => 1000, 'bonus_fee' => 0, 'night_surcharge' => 0,
                'discount_amount' => 0, 'created_at' => now(), 'updated_at' => now(), 'completed_at' => now()->subMinutes($i + 1)];
        }
        DB::table('orders')->insert($rows);

        $page = Livewire::test(RevenueReportPage::class)->call('setPeriod', 'this_month');
        ob_start();
        $page->instance()->exportCsv()->sendContent();
        $csv = ob_get_clean();

        $this->assertSame(520, substr_count($csv, "\nCSV"), 'đủ 520 đơn trong file');
    }

    public function test_previous_period_compares_the_same_length_window_per_bucket(): void
    {
        // Kỳ "7 ngày": cột của hôm nay so với đúng ngày cách đây 7 ngày.
        $this->completed(['shipping_fee' => 40000, 'completed_at' => now()->subDays(7)]);
        $this->completed(['shipping_fee' => 10000, 'completed_at' => now()]);

        $report = Livewire::test(RevenueReportPage::class)->call('setPeriod', 'last_7_days')->instance()->getReportData();
        $today = collect($report['rows'])->firstWhere('key', now()->toDateString());

        $this->assertSame(10000, $today['revenue']);
        $this->assertSame(40000, $today['prev_revenue']);
    }

    public function test_very_long_custom_ranges_are_capped(): void
    {
        $report = Livewire::test(RevenueReportPage::class)->set('from', '2000-01-01')->set('to', now()->toDateString())->instance()->getReportData();

        $this->assertTrue($report['clamped']);
        $this->assertLessThanOrEqual(732, (int) $report['from']->diffInDays($report['to']) + 1);
    }
}
