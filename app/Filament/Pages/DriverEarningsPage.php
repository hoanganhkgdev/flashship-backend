<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\BuildsReportSeries;
use App\Support\AdminAccess;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        $previousTotal = $this->periodTotal($previousFrom, $previousTo);

        $granularity = $this->granularity($days);
        $series = $this->series($from, $to, $granularity);
        $previousSeries = array_values($this->series($previousFrom, $previousTo, $granularity));
        foreach (array_values($series) as $i => $row) {
            $series[$row['key']]['prev_revenue'] = $previousSeries[$i]['revenue'] ?? null;
        }
        $rows = array_values($series);

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
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        [$from, $to] = $this->dateRange();
        $rows = $this->filterAndSort($this->driverRows($from, $to));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Tài xế', 'Số điện thoại', 'Trạng thái', 'Đơn hoàn thành', 'Phí giao', 'Phụ phí', 'Thưởng mưa', 'Bù giảm giá', 'Tổng thu nhập', 'TB / đơn']);
            foreach ($rows as $d) {
                fputcsv($out, [$d['name'], $d['phone'], $d['is_online'] ? 'Online' : 'Offline', $d['orders'], $d['shipping'], $d['surcharge'], $d['rain'], $d['discount'], $d['total'], $d['avg']]);
            }
            fclose($out);
        }, "thu-nhap-tai-xe-{$from->format('Ymd')}-{$to->format('Ymd')}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function cityId(): ?int
    {
        return Filament::getTenant()?->id;
    }

    /** Tài xế thuộc khu vực đang chọn (gồm cả tài khoản bị khóa vì vẫn có thể có thu nhập trong kỳ). */
    private function driverQuery()
    {
        return DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', '>=', 1)
            ->when($this->cityId(), fn ($q, $id) => $q->where('city_id', $id));
    }

    /**
     * Thu nhập gồm: phí giao + phụ phí (đơn hoàn thành trong kỳ) + thưởng mưa + bù giảm giá
     * (các khoản đã ghi có vào ví trong kỳ). Không tính nạp/rút ví, điều chỉnh của admin, phạt.
     */
    private function driverRows(Carbon $from, Carbon $to): array
    {
        $drivers = $this->driverQuery()->orderBy('name')->get(['id', 'name', 'phone', 'is_online', 'status']);
        $ids = $drivers->pluck('id')->all();
        if (! $ids) {
            return [];
        }

        $orders = DB::table('orders')
            ->whereIn('delivery_man_id', $ids)->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COUNT(*) as cnt, COALESCE(SUM(shipping_fee), 0) as shipping, COALESCE(SUM(bonus_fee + night_surcharge), 0) as surcharge')
            ->get()->keyBy('delivery_man_id');

        $rain = $this->walletCredits($ids, 'order\_%\_rain', $from, $to);
        $discount = $this->walletCredits($ids, 'order\_%\_discount', $from, $to);

        return $drivers->map(function ($d) use ($orders, $rain, $discount) {
            $o = $orders->get($d->id);
            $shipping = (int) ($o->shipping ?? 0);
            $surcharge = (int) ($o->surcharge ?? 0);
            $count = (int) ($o->cnt ?? 0);
            $r = (int) ($rain[$d->id] ?? 0);
            $dc = (int) ($discount[$d->id] ?? 0);
            $total = $shipping + $surcharge + $r + $dc;

            return [
                'id' => $d->id, 'name' => (string) $d->name, 'phone' => (string) $d->phone,
                'is_online' => (bool) $d->is_online, 'locked' => (int) $d->status === 2,
                'orders' => $count, 'shipping' => $shipping, 'surcharge' => $surcharge,
                'rain' => $r, 'discount' => $dc, 'total' => $total,
                'avg' => $count ? (int) round($total / $count) : 0,
            ];
        })->all();
    }

    /** @return Collection<int, int> driver_id => số tiền ghi có theo mẫu reference */
    private function walletCredits(array $driverIds, string $referenceLike, Carbon $from, Carbon $to)
    {
        return DB::table('driver_wallet_transactions as t')
            ->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->whereIn('w.driver_id', $driverIds)
            ->where('t.type', 'credit')
            ->where('t.reference', 'like', $referenceLike)
            ->whereBetween('t.created_at', [$from, $to])
            ->groupBy('w.driver_id')
            ->selectRaw('w.driver_id, SUM(t.amount) as amount')
            ->pluck('amount', 'driver_id');
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

    private function periodTotal(Carbon $from, Carbon $to): int
    {
        return array_sum(array_column($this->driverRows($from, $to), 'total'));
    }

    /** Tổng thu nhập theo kỳ (ngày/tuần/tháng) cho cả khu vực, dùng chung với biểu đồ. */
    private function series(Carbon $from, Carbon $to, string $granularity): array
    {
        $buckets = $this->buckets($from, $to, $granularity);
        $ids = $this->driverQuery()->pluck('id')->all();

        $orders = $ids ? DB::table('orders')
            ->whereIn('delivery_man_id', $ids)->where('status', 'completed')->whereBetween('completed_at', [$from, $to])
            ->selectRaw($this->bucketSql('completed_at', $granularity).' as k, COUNT(*) as cnt, COALESCE(SUM(shipping_fee + bonus_fee + night_surcharge), 0) as earnings')
            ->groupBy('k')->get()->keyBy(fn ($r) => (string) $r->k) : collect();

        $wallet = fn (string $like) => $ids ? DB::table('driver_wallet_transactions as t')
            ->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->whereIn('w.driver_id', $ids)->where('t.type', 'credit')->where('t.reference', 'like', $like)
            ->whereBetween('t.created_at', [$from, $to])
            ->selectRaw($this->bucketSql('t.created_at', $granularity).' as k, COALESCE(SUM(t.amount), 0) as amount')
            ->groupBy('k')->pluck('amount', 'k')->mapWithKeys(fn ($v, $k) => [(string) $k => (int) $v]) : collect();
        $rain = $wallet('order\_%\_rain');
        $discount = $wallet('order\_%\_discount');

        $i = 0;
        foreach ($buckets as $key => &$bucket) {
            $o = $orders->get((string) $key);
            $bucket += [
                'idx' => $i++,
                'completed' => (int) ($o->cnt ?? 0),
                'revenue' => (int) ($o->earnings ?? 0) + (int) ($rain[(string) $key] ?? 0) + (int) ($discount[(string) $key] ?? 0),
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
        $ids = $this->driverQuery()->pluck('id')->all();
        $row = $ids ? DB::table('orders')->whereIn('delivery_man_id', $ids)
            ->where('status', 'completed')->whereNull('completed_at')->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(shipping_fee + bonus_fee + night_surcharge), 0) as fee')->first() : null;

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

        return [$from, $to];
    }
}
