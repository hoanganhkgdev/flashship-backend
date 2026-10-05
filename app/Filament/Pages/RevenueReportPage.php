<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\BuildsReportSeries;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\ServiceType;
use Modules\Order\Models\Order;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RevenueReportPage extends Page
{
    use BuildsReportSeries;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Báo cáo';

    protected static ?string $navigationLabel = 'Doanh thu';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Doanh thu';

    protected static string $view = 'filament.pages.revenue-report';

    public string $from = '';

    public string $to = '';

    public string $serviceType = '';

    public string $platform = '';

    public string $paymentMethod = '';

    public string $period = 'this_month';

    public string $sortBy = 'date';

    public string $sortDir = 'desc';

    private const PALETTE = ['#f97316', '#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899', '#94a3b8'];

    private const PRESETS = [
        'today' => 'Hôm nay',
        'last_7_days' => '7 ngày',
        'last_30_days' => '30 ngày',
        'this_month' => 'Tháng này',
        'last_month' => 'Tháng trước',
        'this_quarter' => 'Quý này',
        'this_year' => 'Năm nay',
    ];

    private const SORTABLE = ['date', 'total', 'completed', 'discount', 'revenue'];

    private static array $platformLabels = ['customer_app' => 'App khách', 'shop_app' => 'App shop', 'call_center' => 'Tổng đài', 'admin' => 'Quản trị viên'];

    // Khớp với giá trị thật trong cột orders.payment_method (xem OrderResource).
    private static array $paymentLabels = ['cod' => 'COD (thu hộ)', 'prepaid' => 'Thanh toán trước', 'wallet' => 'Ví'];

    private ?array $serviceLabelCache = null;

    /** Cùng nguồn nhãn với Tổng quan và trang Đơn hàng. */
    private function serviceLabels(): array
    {
        return $this->serviceLabelCache ??= ServiceType::pluck('label', 'key')->toArray();
    }

    public static function canAccess(): bool
    {
        return ! in_array(auth()->user()?->user_type, ['city_manager', 'call_center']);
    }

    public function getHeading(): string
    {
        return '';
    }

    public function mount(): void
    {
        $this->setPeriod('this_month');
    }

    public function setPeriod(string $period): void
    {
        [$from, $to] = match ($period) {
            'today' => [now(), now()],
            'last_7_days' => [now()->subDays(6), now()],
            'last_30_days' => [now()->subDays(29), now()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [now()->startOfQuarter(), now()],
            'this_year' => [now()->startOfYear(), now()],
            default => [now()->startOfMonth(), now()],
        };
        $this->period = array_key_exists($period, self::PRESETS) ? $period : 'this_month';
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function updatedFrom(): void
    {
        $this->period = 'custom';
    }

    public function updatedTo(): void
    {
        $this->period = 'custom';
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }
        $this->sortDir = $this->sortBy === $column && $this->sortDir === 'desc' ? 'asc' : 'desc';
        $this->sortBy = $column;
    }

    public function clearFilters(): void
    {
        $this->serviceType = $this->platform = $this->paymentMethod = '';
    }

    public function getPresets(): array
    {
        return self::PRESETS;
    }

    public function getActiveFilterCount(): int
    {
        return count(array_filter([$this->serviceType, $this->platform, $this->paymentMethod]));
    }

    public function getFilterOptions(): array
    {
        return ['services' => $this->serviceLabels(), 'platforms' => self::$platformLabels, 'payments' => self::$paymentLabels];
    }

    public function getReportData(): array
    {
        [$from, $to] = $this->dateRange();
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        $summary = $this->summaryForPeriod($from, $to);
        $previous = $this->summaryForPeriod($previousFrom, $previousTo);
        $summary['revenue_change'] = $this->percentChange($summary['total_revenue'], $previous['total_revenue']);
        $summary['orders_change'] = $this->percentChange($summary['completed_orders'], $previous['completed_orders']);
        // Tính trên đơn TẠO trong kỳ rồi đã hoàn thành, nên không bao giờ vượt 100%.
        $summary['completion_rate'] = $summary['total_orders'] ? round($summary['created_completed'] / $summary['total_orders'] * 100, 1) : 0;

        $granularity = $this->granularity($days);
        $series = $this->series($from, $to, $granularity, true);
        $previousSeries = array_values($this->series($previousFrom, $previousTo, $granularity, false));
        foreach (array_values($series) as $i => $row) {
            $series[$row['key']]['prev_revenue'] = $previousSeries[$i]['revenue'] ?? null;
        }
        $rows = array_values($series);

        $services = $this->breakdown('service_type', $from, $to, $this->serviceLabels());
        $platforms = $this->breakdown('platform', $from, $to, self::$platformLabels);
        $payments = $this->breakdown('payment_method', $from, $to, self::$paymentLabels);

        return [
            'summary' => $summary,
            'from' => $from, 'to' => $to,
            'previous_from' => $previousFrom, 'previous_to' => $previousTo,
            'granularity' => $granularity,
            'granularity_label' => ['day' => 'theo ngày', 'week' => 'theo tuần', 'month' => 'theo tháng'][$granularity],
            'chart' => $this->chartData($rows),
            'rows' => $this->sortRows($rows),
            'totals' => [
                'total' => array_sum(array_column($rows, 'total')),
                'completed' => array_sum(array_column($rows, 'completed')),
                'discount' => array_sum(array_column($rows, 'discount')),
                'revenue' => array_sum(array_column($rows, 'revenue')),
            ],
            'services' => $this->withColors($services),
            'platforms' => $this->withColors($platforms),
            'payments' => $payments,
            'quality' => $this->dataQuality($from, $to),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        [$from, $to] = $this->dateRange();

        return response()->streamDownload(function () use ($from, $to): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Mã đơn', 'Hoàn thành', 'Dịch vụ', 'Nguồn đơn', 'Thanh toán', 'Phí ship', 'Phụ phí', 'Giảm giá', 'Tổng thu']);
            $this->completedQuery($from, $to)->orderBy('completed_at')->chunkById(500, function ($orders) use ($output): void {
                foreach ($orders as $order) {
                    $surcharge = (int) $order->bonus_fee + (int) $order->night_surcharge;
                    fputcsv($output, [$order->code ?: $order->id, optional($order->completed_at)->format('d/m/Y H:i'), $this->serviceLabels()[$order->service_type] ?? $order->service_type, self::$platformLabels[$order->platform] ?? $order->platform, self::$paymentLabels[$order->payment_method] ?? $order->payment_method, (int) $order->shipping_fee, $surcharge, (int) $order->discount_amount, (int) $order->shipping_fee + $surcharge]);
                }
            });
            fclose($output);
        }, "bao-cao-doanh-thu-{$from->format('Ymd')}-{$to->format('Ymd')}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function summaryForPeriod(Carbon $from, Carbon $to): array
    {
        $operational = $this->filteredQuery()->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("SUM(CASE WHEN status IN ('pending','assigned','processing') THEN 1 ELSE 0 END) as active_orders")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as created_completed")->first();
        $financial = $this->completedQuery($from, $to)
            ->selectRaw('COUNT(*) as completed_orders, COALESCE(SUM(shipping_fee), 0) as shipping_revenue')
            ->selectRaw('COALESCE(SUM(bonus_fee + night_surcharge), 0) as surcharge_revenue, COALESCE(SUM(discount_amount), 0) as total_discount')
            ->selectRaw('COALESCE(SUM(rain_bonus_amount), 0) as rain_bonus')->first();
        $shipping = (int) $financial->shipping_revenue;
        $surcharge = (int) $financial->surcharge_revenue;
        $completed = (int) $financial->completed_orders;

        return [
            'total_orders' => (int) $operational->total_orders,
            'completed_orders' => $completed,
            'active_orders' => (int) $operational->active_orders,
            'cancelled_orders' => (int) $operational->cancelled_orders,
            'created_completed' => (int) $operational->created_completed,
            'shipping_revenue' => $shipping,
            'surcharge_revenue' => $surcharge,
            'total_discount' => (int) $financial->total_discount,
            'rain_bonus' => (int) $financial->rain_bonus,
            'total_revenue' => $shipping + $surcharge,
            'avg_fee' => $completed ? (int) round(($shipping + $surcharge) / $completed) : 0,
        ];
    }

    /**
     * Chuỗi theo kỳ (ngày/tuần/tháng). $withCreated=false bỏ truy vấn đơn tạo (kỳ trước chỉ cần doanh thu).
     *
     * @return array<string, array<string, mixed>>
     */
    private function series(Carbon $from, Carbon $to, string $granularity, bool $withCreated): array
    {
        $buckets = $this->buckets($from, $to, $granularity);

        $completed = $this->completedQuery($from, $to)
            ->selectRaw($this->bucketSql('completed_at', $granularity).' as k, COUNT(*) as completed, COALESCE(SUM(shipping_fee + bonus_fee + night_surcharge), 0) as revenue, COALESCE(SUM(discount_amount), 0) as discount')
            ->groupBy('k')->get()->keyBy(fn ($r) => (string) $r->k);

        $created = $withCreated
            ? $this->filteredQuery()->whereBetween('created_at', [$from, $to])
                ->selectRaw($this->bucketSql('created_at', $granularity).' as k, COUNT(*) as total')
                ->groupBy('k')->get()->mapWithKeys(fn ($r) => [(string) $r->k => (int) $r->total])
            : collect();

        $i = 0;
        foreach ($buckets as $key => &$bucket) {
            $row = $completed->get((string) $key);
            $bucket += [
                'idx' => $i++,
                'total' => (int) ($created[(string) $key] ?? 0),
                'completed' => (int) ($row?->completed ?? 0),
                'revenue' => (int) ($row?->revenue ?? 0),
                'discount' => (int) ($row?->discount ?? 0),
                'prev_revenue' => null,
            ];
        }
        unset($bucket);

        return $buckets;
    }

    private function sortRows(array $rows): array
    {
        $by = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'date';
        $column = $by === 'date' ? 'idx' : $by;
        usort($rows, fn ($a, $b) => $this->sortDir === 'asc' ? $a[$column] <=> $b[$column] : $b[$column] <=> $a[$column]);

        return $rows;
    }

    /** Gắn màu và tỷ trọng; chỉ giữ 6 mục lớn nhất, phần còn lại gộp thành "Khác". */
    private function withColors(array $rows): array
    {
        if (count($rows) > 6) {
            $rest = array_slice($rows, 6);
            $rows = array_slice($rows, 0, 6);
            $rows[] = ['key' => 'other', 'label' => 'Khác', 'total' => array_sum(array_column($rest, 'total')), 'revenue' => array_sum(array_column($rest, 'revenue'))];
        }
        $sum = max(1, array_sum(array_column($rows, 'revenue')));
        $acc = 0;
        $stops = [];
        foreach ($rows as $i => &$row) {
            $row['color'] = self::PALETTE[$i % count(self::PALETTE)];
            $row['share'] = round($row['revenue'] / $sum * 100, 1);
            $stops[] = $row['color'].' '.round($acc, 2).'% '.round($acc += $row['revenue'] / $sum * 100, 2).'%';
        }
        unset($row);

        return ['rows' => $rows, 'gradient' => $stops ? implode(', ', $stops) : '#94a3b8 0% 100%'];
    }

    /** Đơn hoàn thành thiếu completed_at sẽ không lọt vào báo cáo — báo cho người xem biết. */
    private function dataQuality(Carbon $from, Carbon $to): array
    {
        $row = $this->filteredQuery()->where('status', 'completed')->whereNull('completed_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(shipping_fee + bonus_fee + night_surcharge), 0) as fee')->first();

        return ['missing_count' => (int) $row->c, 'missing_fee' => (int) $row->fee];
    }

    private function breakdown(string $column, Carbon $from, Carbon $to, array $labels): array
    {
        return $this->completedQuery($from, $to)->select($column)->selectRaw('COUNT(*) as total, COALESCE(SUM(shipping_fee + bonus_fee + night_surcharge), 0) as revenue')->groupBy($column)->orderByDesc('revenue')->get()->map(fn ($row) => ['key' => $row->{$column} ?: 'unknown', 'label' => $labels[$row->{$column}] ?? ($row->{$column} ?: 'Chưa xác định'), 'total' => (int) $row->total, 'revenue' => (int) $row->revenue])->all();
    }

    private function filteredQuery(): Builder
    {
        return Order::query()->when(Filament::getTenant()?->getKey(), fn (Builder $q, $id) => $q->where('city_id', $id))->when($this->serviceType, fn (Builder $q) => $q->where('service_type', $this->serviceType))->when($this->platform, fn (Builder $q) => $q->where('platform', $this->platform))->when($this->paymentMethod, fn (Builder $q) => $q->where('payment_method', $this->paymentMethod));
    }

    private function completedQuery(Carbon $from, Carbon $to): Builder
    {
        return $this->filteredQuery()->where('status', 'completed')->whereBetween('completed_at', [$from, $to]);
    }

    private function dateRange(): array
    {
        try {
            $from = Carbon::parse($this->from)->startOfDay();
            $to = Carbon::parse($this->to)->endOfDay();
        } catch (\Throwable) {
            $from = now()->startOfMonth();
            $to = now()->endOfDay();
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }
}
