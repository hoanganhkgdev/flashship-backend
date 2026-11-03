<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\BuildsReportSeries;
use App\Support\AdminAccess;
use App\Support\Csv;
use App\Support\OrderMoney;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperationalSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverEarningsPage extends Page
{
    use BuildsReportSeries;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Báo cáo';

    protected static ?string $navigationLabel = 'Thu nhập tài xế';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Thu nhập tài xế';

    protected static string $view = 'filament.pages.driver-earnings';

    public string $from = '';

    public string $to = '';

    public string $period = 'this_week';

    public string $search = '';

    public string $onlineStatus = 'all';

    public string $activity = 'has_orders';

    public string $sortBy = 'total';

    public string $sortDir = 'desc';

    public int $limit = 25;

    private const PAGE_SIZE = 25;

    private const PRESETS = [
        'today' => 'Hôm nay',
        'yesterday' => 'Hôm qua',
        'last_7_days' => '7 ngày',
        'this_week' => 'Tuần này',
        'this_month' => 'Tháng này',
        'last_month' => 'Tháng trước',
    ];

    private const SORTABLE = ['name', 'orders', 'shipping', 'surcharge', 'rain', 'discount', 'total', 'avg'];

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::FINANCE_VIEW);
    }

    /** File xuất có tên, SĐT và thu nhập từng tài xế: cần quyền xuất dữ liệu, không chỉ quyền xem. */
    public function canExport(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::EXPORT_DATA);
    }

    private bool $rangeWasClamped = false;

    public function mount(): void
    {
        $this->setPeriod('this_week');
    }

    public function setPeriod(string $period): void
    {
        [$from, $to] = match ($period) {
            'today' => [now(), now()],
            'yesterday' => [now()->subDay(), now()->subDay()],
            'last_7_days' => [now()->subDays(6), now()],
            'this_month' => [now()->startOfMonth(), now()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            default => [now()->startOfWeek(), now()],
        };
        $this->period = array_key_exists($period, self::PRESETS) ? $period : 'this_week';
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
        $this->limit = self::PAGE_SIZE;
    }

    public function updatedFrom(): void
    {
        $this->period = 'custom';
        $this->limit = self::PAGE_SIZE;
    }

    public function updatedTo(): void
    {
        $this->period = 'custom';
        $this->limit = self::PAGE_SIZE;
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE_SIZE;
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }
        $this->sortDir = $this->sortBy === $column && $this->sortDir === 'desc' ? 'asc' : ($column === 'name' && $this->sortBy !== 'name' ? 'asc' : 'desc');
        $this->sortBy = $column;
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function getPresets(): array
    {
        return self::PRESETS;
    }

    public function getReportData(): array
    {
        [$from, $to] = $this->dateRange();
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        $drivers = $this->driverRows($from, $to);
        $totals = $this->totals($drivers);
        $previousTotal = (int) $this->completedQuery($previousFrom, $previousTo)->sum(DB::raw($this->earningsSql()));

        $granularity = $this->granularity($days);
        $previousDaily = $this->completedQuery($previousFrom, $previousTo)
            ->selectRaw('DATE(completed_at) as d, COALESCE(SUM('.$this->earningsSql().'), 0) as v')
            ->groupBy('d')->pluck('v', 'd');
        $rows = array_values($this->attachPrevious($this->series($from, $to, $granularity), $previousDaily, $days));

        $visible = $this->filterAndSort($drivers);

        return [
            'from' => $from, 'to' => $to,
            'previous_from' => $previousFrom, 'previous_to' => $previousTo,
            'granularity' => $granularity,
            'granularity_label' => ['day' => 'theo ngày', 'week' => 'theo tuần', 'month' => 'theo tháng'][$granularity],
            'totals' => $totals + [
                'earnings_change' => $this->percentChange($totals['total'], $previousTotal),
                'orders_change' => null,
            ],
            'chart' => $this->chartData($rows),
            'drivers' => array_slice($visible, 0, $this->limit),
            'visible_count' => count($visible),
            'has_more' => count($visible) > $this->limit,
            'quality' => $this->dataQuality($from, $to),
            'clamped' => $this->rangeWasClamped,
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);
        [$from, $to] = $this->dateRange();
        $rows = $this->filterAndSort($this->driverRows($from, $to));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Tài xế', 'Số điện thoại', 'Trạng thái', 'Đơn hoàn thành', 'Phí giao', 'Bonus', 'Thưởng mưa', 'Bù giảm giá', 'Tổng thu nhập', 'TB / đơn']);
            foreach ($rows as $d) {
                fputcsv($out, Csv::row([$d['name'], $d['phone'], $d['is_online'] ? 'Online' : 'Offline', $d['orders'], $d['shipping'], $d['surcharge'], $d['rain'], $d['discount'], $d['total'], $d['avg']]));
            }
            fclose($out);
        }, "thu-nhap-tai-xe-{$from->format('Ymd')}-{$to->format('Ymd')}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function cityId(): int
    {
        return (int) (Filament::getTenant()?->getKey() ?? 0);
    }

    /**
     * Đơn hoàn thành trong kỳ CỦA KHU VỰC (theo khu vực của đơn, không theo khu vực hiện tại của tài xế): tài xế đổi
     * khu vực thì thu nhập đã làm vẫn thuộc khu vực cũ, và tổng khớp trang Doanh thu.
     */
    private function completedQuery(Carbon $from, Carbon $to): Builder
    {
        return DB::table('orders')
            ->where('city_id', $this->cityId())
            ->where('status', 'completed')
            ->whereNotNull('delivery_man_id')
            ->whereBetween('completed_at', [$from, $to]);
    }

    /** Thưởng mưa chưa chốt trên đơn thì tính theo mức của khu vực, như OrderService::completeOrder(). */
    private function rainSql(): string
    {
        return OrderMoney::rainCost(OperationalSettings::rainBonusAmount($this->cityId() ?: null));
    }

    /**
     * Thu nhập của một đơn = khách trả + công ty chi (App\Support\OrderMoney, khớp completeOrder()). Phí giao gồm cả phí
     * freeship công ty trả thay khách; phụ phí đêm đã nằm trong phí giao.
     */
    private function earningsSql(): string
    {
        return OrderMoney::driverEarnings(OperationalSettings::rainBonusAmount($this->cityId() ?: null));
    }

    /**
     * Thu nhập theo tài xế: phí giao + bonus + thưởng mưa + bù giảm giá của các đơn hoàn thành trong kỳ (cùng một ngày
     * hoàn thành cho mọi khoản). Danh sách gồm tài xế đang thuộc khu vực và cả người đã chuyển đi nhưng có đơn ở đây
     * trong kỳ. Không tính nạp/rút ví, điều chỉnh của admin, phạt.
     */
    private function driverRows(Carbon $from, Carbon $to): array
    {
        $orders = $this->completedQuery($from, $to)
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM('.OrderMoney::COLLECTED.' + '.OrderMoney::FREESHIP_COST.'), 0) as shipping')
            ->selectRaw('COALESCE(SUM('.OrderMoney::BONUS_COST.'), 0) as bonus, COALESCE(SUM('.OrderMoney::DISCOUNT_COST.'), 0) as discount')
            ->selectRaw('COALESCE(SUM('.$this->rainSql().'), 0) as rain')
            ->get()->keyBy('delivery_man_id');

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', '>=', 1) // gồm cả tài khoản bị khóa vì vẫn có thể có thu nhập trong kỳ
            ->where(fn ($q) => $q->where('city_id', $this->cityId())->orWhereIn('id', $orders->keys()->all()))
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'is_online', 'status']);

        return $drivers->map(function ($d) use ($orders) {
            $o = $orders->get($d->id);
            $shipping = (int) ($o->shipping ?? 0);
            $bonus = (int) ($o->bonus ?? 0);
            $rain = (int) ($o->rain ?? 0);
            $discount = (int) ($o->discount ?? 0);
            $count = (int) ($o->cnt ?? 0);
            $total = $shipping + $bonus + $rain + $discount;

            return [
                'id' => $d->id, 'name' => (string) $d->name, 'phone' => (string) $d->phone,
                'is_online' => (bool) $d->is_online, 'locked' => (int) $d->status === 2,
                // 'surcharge' giữ tên khóa cũ (cột sắp xếp, CSS) nhưng là bonus_fee.
                'orders' => $count, 'shipping' => $shipping, 'surcharge' => $bonus,
                'rain' => $rain, 'discount' => $discount, 'total' => $total,
                'avg' => $count ? (int) round($total / $count) : 0,
            ];
        })->all();
    }

    private function totals(array $drivers): array
    {
        $earning = array_filter($drivers, fn ($d) => $d['total'] > 0 || $d['orders'] > 0);
        $total = array_sum(array_column($drivers, 'total'));
        $orders = array_sum(array_column($drivers, 'orders'));

        return [
            'drivers' => count($drivers),
            'online' => count(array_filter($drivers, fn ($d) => $d['is_online'])),
            'earning_drivers' => count($earning),
            'orders' => $orders,
            'shipping' => array_sum(array_column($drivers, 'shipping')),
            'surcharge' => array_sum(array_column($drivers, 'surcharge')),
            'rain' => array_sum(array_column($drivers, 'rain')),
            'discount' => array_sum(array_column($drivers, 'discount')),
            'total' => $total,
            'avg_per_driver' => count($earning) ? (int) round($total / count($earning)) : 0,
            'avg_per_order' => $orders ? (int) round($total / $orders) : 0,
        ];
    }

    /** Tổng thu nhập theo kỳ (ngày/tuần/tháng) cho cả khu vực, dùng chung với biểu đồ. */
    private function series(Carbon $from, Carbon $to, string $granularity): array
    {
        $buckets = $this->buckets($from, $to, $granularity);
        $orders = $this->completedQuery($from, $to)
            ->selectRaw($this->bucketSql('completed_at', $granularity).' as k, COUNT(*) as cnt, COALESCE(SUM('.$this->earningsSql().'), 0) as earnings')
            ->groupBy('k')->get()->keyBy(fn ($r) => (string) $r->k);

        $i = 0;
        foreach ($buckets as $key => &$bucket) {
            $o = $orders->get((string) $key);
            $bucket += [
                'idx' => $i++,
                'completed' => (int) ($o->cnt ?? 0),
                'revenue' => (int) ($o->earnings ?? 0),
                'prev_revenue' => null,
            ];
        }
        unset($bucket);

        return $buckets;
    }

    private function filterAndSort(array $drivers): array
    {
        $search = mb_strtolower(trim($this->search));
        $rows = array_values(array_filter($drivers, function (array $d) use ($search) {
            if ($search !== '' && ! str_contains(mb_strtolower($d['name'].' '.$d['phone']), $search)) {
                return false;
            }
            if ($this->onlineStatus === 'online' && ! $d['is_online']) {
                return false;
            }
            if ($this->onlineStatus === 'offline' && $d['is_online']) {
                return false;
            }
            if ($this->activity === 'has_orders' && $d['total'] <= 0 && $d['orders'] === 0) {
                return false;
            }
            if ($this->activity === 'no_orders' && ($d['total'] > 0 || $d['orders'] > 0)) {
                return false;
            }

            return true;
        }));

        $by = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'total';
        $dir = $this->sortDir === 'asc' ? 1 : -1;
        usort($rows, fn ($a, $b) => $by === 'name'
            ? $dir * strcasecmp($a['name'], $b['name'])
            : ($dir * ($a[$by] <=> $b[$by]) ?: strcasecmp($a['name'], $b['name'])));

        // Tỷ trọng so với tổng thu nhập của cả khu vực (không phụ thuộc bộ lọc đang bật).
        $sum = max(1, array_sum(array_column($drivers, 'total')));
        foreach ($rows as &$row) {
            $row['share'] = round($row['total'] / $sum * 100, 1);
        }
        unset($row);

        return $rows;
    }

    /** Đơn hoàn thành thiếu completed_at sẽ không được tính vào thu nhập — báo cho người xem biết. */
    private function dataQuality(Carbon $from, Carbon $to): array
    {
        $row = DB::table('orders')
            ->where('city_id', $this->cityId())->where('status', 'completed')->whereNotNull('delivery_man_id')
            ->whereNull('completed_at')->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM('.$this->earningsSql().'), 0) as fee')->first();

        return ['missing_count' => (int) ($row->c ?? 0), 'missing_fee' => (int) ($row->fee ?? 0)];
    }

    private function dateRange(): array
    {
        try {
            $from = Carbon::parse($this->from)->startOfDay();
            $to = Carbon::parse($this->to)->endOfDay();
        } catch (\Throwable) {
            $from = now()->startOfWeek();
            $to = now()->endOfDay();
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        [$from, $to, $this->rangeWasClamped] = $this->clampRange($from, $to);

        return [$from, $to];
    }
}
