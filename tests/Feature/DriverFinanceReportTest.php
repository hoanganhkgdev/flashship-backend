<?php

namespace Tests\Feature;

use App\Filament\Pages\DriverFinanceReportPage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Tests\TestCase;

class DriverFinanceReportTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private string $week;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->travelTo(now()->setTime(12, 0));
        $this->week = now()->startOfWeek()->toDateString();
        $this->city = City::create(['name' => 'FR city', 'slug' => 'fr-city-'.uniqid(), 'is_active' => true]);
        $this->actingAs($this->user('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type, array $extra = []): User
    {
        return User::create($extra + [
            'name' => ucfirst($type).' fr', 'email' => 'fr-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function debt(User $driver, array $attrs = []): int
    {
        return DB::table('driver_debts')->insertGetId($attrs + [
            'driver_id' => $driver->id, 'debt_type' => 'weekly', 'status' => 'pending', 'amount_due' => 100000, 'amount_paid' => 0,
            'week_start' => $this->week, 'week_end' => now()->endOfWeek()->toDateString(), 'note' => 'Phí tuần', 'ref_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function report(): array
    {
        return Livewire::test(DriverFinanceReportPage::class)->instance()->getReportData();
    }

    public function test_categories_follow_debt_type_and_reference_and_cod_is_kept_apart(): void
    {
        $driver = $this->user('driver');
        $this->debt($driver, ['amount_due' => 200000, 'note' => 'Ghi chú bất kỳ']);                         // phí tuần, ghi chú khác chuẩn
        $this->debt($driver, ['amount_due' => 30000, 'ref_id' => 'shift_offline_1_2_x', 'note' => 'Phạt Offline']);
        $this->debt($driver, ['amount_due' => 20000, 'ref_id' => 'score_penalty_1_x', 'note' => 'Phạt điểm']);
        $this->debt($driver, ['amount_due' => 50000, 'ref_id' => 'manual_x', 'note' => 'Thu khác']);
        $this->debt($driver, ['debt_type' => 'cod', 'amount_due' => 500000, 'note' => 'COD']);

        $t = $this->report()['totals'];

        $this->assertSame(200000, $t['fee_due']);
        $this->assertSame(50000, $t['penalty'], 'phạt offline và phạt điểm');
        $this->assertSame(50000, $t['other']);
        $this->assertSame(300000, $t['total_due'], 'COD không nằm trong phải thu');
        $this->assertSame(500000, $t['cod_due']);
        $this->assertSame(500000, $t['cod_remaining']);
    }

    public function test_waivers_are_shown_instead_of_shrinking_the_amount_due(): void
    {
        $driver = $this->user('driver');
        // Phí 100k, thu 40k rồi miễn 60k: waive() hạ amount_due về 40k và ghi 60k vào nhật ký.
        $id = $this->debt($driver, ['amount_due' => 40000, 'amount_paid' => 40000, 'status' => 'paid', 'paid_via' => 'waived']);
        DB::table('driver_debt_logs')->insert(['debt_id' => $id, 'action' => 'waived', 'amount' => 60000, 'created_at' => now()]);

        $t = $this->report()['totals'];

        $this->assertSame(100000, $t['total_due'], 'phải thu gốc');
        $this->assertSame(40000, $t['total_paid']);
        $this->assertSame(60000, $t['waived']);
        $this->assertSame(0, $t['remaining']);
        $this->assertEquals(40.0, $t['collect_rate'], 'tiến độ không còn báo 100% khi phần lớn bị miễn');
    }

    public function test_cash_collected_counts_payments_made_in_the_period(): void
    {
        $driver = $this->user('driver');
        $old = $this->debt($driver, ['week_start' => now()->subWeeks(3)->startOfWeek()->toDateString(), 'amount_paid' => 100000, 'status' => 'paid']);
        DB::table('driver_debt_logs')->insert(['debt_id' => $old, 'action' => 'paid_payos', 'amount' => 100000, 'created_at' => now()->subWeeks(5)]);
        DB::table('driver_debt_logs')->insert(['debt_id' => $old, 'action' => 'paid_manual', 'amount' => 25000, 'created_at' => now()]);

        $cash = $this->report()['cash'];

        $this->assertSame(25000, $cash['amount'], 'tiền trả trong tuần này, dù là nợ của kỳ trước');
        $this->assertFalse($cash['partial']);
    }

    public function test_other_cities_settled_filter_and_top_debtor(): void
    {
        $other = City::create(['name' => 'Khác', 'slug' => 'k-'.uniqid(), 'is_active' => true]);
        $this->debt($this->user('driver', ['city_id' => $other->id]), ['amount_due' => 999000]);
        $big = $this->user('driver', ['name' => 'Zz nợ nhiều']);
        $this->debt($big, ['amount_due' => 300000]);
        $withdrawOnly = $this->user('driver', ['name' => 'Chỉ rút tiền']);
        DB::table('withdraw_requests')->insert(['driver_id' => $withdrawOnly->id, 'amount' => 50000, 'status' => 'approved',
            'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $page = Livewire::test(DriverFinanceReportPage::class)->set('sortBy', 'name')->set('sortDir', 'asc')->set('limit', 25);
        $report = $page->instance()->getReportData();

        $this->assertSame(300000, $report['totals']['total_due'], 'không lẫn nợ của khu vực khác');
        $this->assertSame(1, $report['totals']['drivers'], 'người chỉ rút tiền không tính là có phát sinh phí');
        $this->assertSame($big->id, $report['top_debtor']['id']);

        $settled = $page->set('debtStatus', 'settled')->instance()->getReportData();
        $this->assertNotContains($withdrawOnly->id, array_column($settled['drivers'], 'id'));
    }

    public function test_tampered_period_and_limit_are_normalised(): void
    {
        $page = Livewire::test(DriverFinanceReportPage::class)->set('date', 'abc')->set('limit', -5)->set('mode', 'year');
        $report = $page->instance()->getReportData();

        $this->assertSame(now()->startOfWeek()->toDateString(), $report['from']->toDateString());
        $this->assertGreaterThanOrEqual(25, $page->instance()->limit);

        $future = Livewire::test(DriverFinanceReportPage::class)->set('date', '2099-01-05')->instance()->getReportData();
        $this->assertSame(now()->startOfWeek()->toDateString(), $future['from']->toDateString(), 'kỳ ngoài dropdown về kỳ hiện tại');
    }

    public function test_export_neutralises_formulas(): void
    {
        $driver = $this->user('driver', ['name' => '=HYPERLINK("http://evil","x")']);
        $this->debt($driver);

        $response = Livewire::test(DriverFinanceReportPage::class)->instance()->export();
        ob_start();
        $response->sendContent();
        $xlsx = ob_get_clean();

        $zip = tempnam(sys_get_temp_dir(), 'fr');
        file_put_contents($zip, $xlsx);
        $archive = new \ZipArchive;
        $archive->open($zip);
        $content = '';
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $content .= $archive->getFromIndex($i);
        }
        $archive->close();
        @unlink($zip);

        $this->assertStringContainsString("'=HYPERLINK", html_entity_decode($content, ENT_QUOTES | ENT_XML1));
    }
}
