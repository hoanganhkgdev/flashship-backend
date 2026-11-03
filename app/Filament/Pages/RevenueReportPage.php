<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\BuildsReportSeries;
use App\Support\AdminAccess;
use App\Support\OrderMoney;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\ServiceType;
use Modules\Core\Services\OperationalSettings;
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

    private const SORTABLE = ['date', 'total', 'completed', 'cost', 'revenue'];



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
        return AdminAccess::allows(auth()->user(), AdminAccess::REPORTS_VIEW);
    }

    /** Xuất file chứa số liệu từng đơn: cùng quyền với xuất ở trang Đơn hàng, không chỉ quyền xem báo cáo. */
    public function canExport(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::EXPORT_DATA);
    }

    /** Thưởng mưa chưa chốt trên đơn thì tính theo mức của khu vực, như OrderService::completeOrder(). */
    private function rainDefault(): int
    {
        return OperationalSettings::rainBonusAmount(Filament::getTenant()?->getKey());
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
        $summary['cost_change'] = $this->percentChange($summary['platform_cost'], $previous['platform_cost']);
        $summary['driver_change'] = $this->percentChange($summary['driver_total'], $previous['driver_total']);
        // Tính trên đơn TẠO trong kỳ rồi đã hoàn thành, nên không bao giờ vượt 100%.
        $summary['completion_rate'] = $summary['total_orders'] ? round($summary['created_completed'] / $summary['total_orders'] * 100, 1) : 0;

        $granularity = $this->granularity($days);
        $series = $this->series($from, $to, $granularity);
        // Kỳ trước so theo đúng khoảng ngày của từng cột lùi lại $days ngày (không ghép theo vị trí:
        // tuần/tháng của hai kỳ dài ngắn khác nhau, ghép vị trí làm lệch cả tháng).
        $previousDaily = $this->completedQuery($previousFrom, $previousTo)
            ->selectRaw('DATE(completed_at) as d, COALESCE(SUM('.OrderMoney::COLLECTED.'), 0) as v')
            ->groupBy('d')->pluck('v', 'd');
        $rows = array_values($this->attachPrevious($series, $previousDaily, $days));

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
                'cost' => array_sum(array_column($rows, 'cost')),
                'revenue' => array_sum(array_column($rows, 'revenue')),
            ],
            'clamped' => $this->rangeWasClamped,
            'services' => $this->withColors($services),
            'platforms' => $this->withColors($platforms),
            'payments' => $payments,
            'quality' => $this->dataQuality($from, $to),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);
        [$from, $to] = $this->dateRange();
        $rain = OrderMoney::rainCost($this->rainDefault());

        return response()->streamDownload(function () use ($from, $to, $rain): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Mã đơn', 'Hoàn thành', 'Dịch vụ', 'Nguồn đơn', 'Thanh toán', 'Khách trả', 'Trong đó phụ phí đêm', 'Bonus tài xế', 'Bù voucher', 'Bù freeship', 'Thưởng mưa', 'Công ty chi']);
            // lazy() phân trang theo đúng thứ tự (completed_at, id); chunkById sắp lại theo id nên bỏ sót đơn hoàn thành không theo thứ tự id.
            $this->completedQuery($from, $to)
                ->select(['id', 'code', 'completed_at', 'service_type', 'platform', 'payment_method'])
                ->selectRaw(OrderMoney::COLLECTED.' as m_collected, '.OrderMoney::NIGHT_INCLUDED.' as m_night, '.OrderMoney::BONUS_COST.' as m_bonus')
                ->selectRaw(OrderMoney::DISCOUNT_COST.' as m_discount, '.OrderMoney::FREESHIP_COST.' as m_freeship, '.$rain.' as m_rain')
                ->orderBy('completed_at')->orderBy('id')
                ->lazy(500)
                ->each(function ($order) use ($output): void {
                    $cost = (int) $order->m_bonus + (int) $order->m_discount + (int) $order->m_freeship + (int) $order->m_rain;
                    fputcsv($output, [$order->code ?: $order->id, optional($order->completed_at)->format('d/m/Y H:i'), $this->serviceLabels()[$order->service_type] ?? $order->service_type, self::$platformLabels[$order->platform] ?? $order->platform, self::$paymentLabels[$order->payment_method] ?? $order->payment_method, (int) $order->m_collected, (int) $order->m_night, (int) $order->m_bonus, (int) $order->m_discount, (int) $order->m_freeship, (int) $order->m_rain, $cost]);
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
            ->selectRaw('COUNT(*) as completed_orders')
            ->selectRaw('COALESCE(SUM('.OrderMoney::COLLECTED.'), 0) as collected, COALESCE(SUM('.OrderMoney::NIGHT_INCLUDED.'), 0) as night')
            ->selectRaw('COALESCE(SUM('.OrderMoney::BONUS_COST.'), 0) as bonus, COALESCE(SUM('.OrderMoney::DISCOUNT_COST.'), 0) as discount')
            ->selectRaw('COALESCE(SUM('.OrderMoney::FREESHIP_COST.'), 0) as freeship, COALESCE(SUM('.OrderMoney::rainCost($this->rainDefault()).'), 0) as rain')
            ->first();
        $collected = (int) $financial->collected;
        $completed = (int) $financial->completed_orders;
        $costs = [
            'bonus' => (int) $financial->bonus,
            'discount' => (int) $financial->discount,
            'freeship' => (int) $financial->freeship,
            'rain' => (int) $financial->rain,
        ];

        return [
            'total_orders' => (int) $operational->total_orders,
            'completed_orders' => $completed,
            'active_orders' => (int) $operational->active_orders,
            'cancelled_orders' => (int) $operational->cancelled_orders,
            'created_completed' => (int) $operational->created_completed,
            // Doanh thu = tiền khách thật sự trả (đã gồm phụ phí đêm, đã trừ voucher, freeship tổng đài = 0).
            'total_revenue' => $collected,
            'night_included' => (int) $financial->night,
            'costs' => $costs,
            'platform_cost' => array_sum($costs),
            'driver_total' => $collected + array_sum($costs),
            'avg_fee' => $completed ? (int) round($collected / $completed) : 0,
        ];
    }

    /**
     * Chuỗi theo kỳ (ngày/tuần/tháng): đơn tạo, đơn hoàn thành, tiền khách trả và công ty chi.
     *
     * @return array<string, array<string, mixed>>
     */
    private function series(Carbon $from, Carbon $to, string $granularity): array
    {
        $buckets = $this->buckets($from, $to, $granularity);

        $completed = $this->completedQuery($from, $to)
            ->selectRaw($this->bucketSql('completed_at', $granularity).' as k, COUNT(*) as completed')
            ->selectRaw('COALESCE(SUM('.OrderMoney::COLLECTED.'), 0) as revenue, COALESCE(SUM('.OrderMoney::platformCost($this->rainDefault()).'), 0) as cost')
            ->groupBy('k')->get()->keyBy(fn ($r) => (string) $r->k);

        $created = $this->filteredQuery()->whereBetween('created_at', [$from, $to])
            ->selectRaw($this->bucketSql('created_at', $granularity).' as k, COUNT(*) as total')
            ->groupBy('k')->get()->mapWithKeys(fn ($r) => [(string) $r->k => (int) $r->total]);

        $i = 0;
        foreach ($buckets as $key => &$bucket) {
            $row = $completed->get((string) $key);
            $bucket += [
                'idx' => $i++,
                'total' => (int) ($created[(string) $key] ?? 0),
                'completed' => (int) ($row?->completed ?? 0),
                'revenue' => (int) ($row?->revenue ?? 0),
                'cost' => (int) ($row?->cost ?? 0),
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
            ->selectRaw('COUNT(*) as c, COALESCE(SUM('.OrderMoney::COLLECTED.'), 0) as fee')->first();

        return ['missing_count' => (int) $row->c, 'missing_fee' => (int) $row->fee];
    }

    private function breakdown(string $column, Carbon $from, Carbon $to, array $labels): array
    {
        return $this->completedQuery($from, $to)->select($column)->selectRaw('COUNT(*) as total, COALESCE(SUM('.OrderMoney::COLLECTED.'), 0) as revenue')->groupBy($column)->orderByDesc('revenue')->get()->map(fn ($row) => ['key' => $row->{$column} ?: 'unknown', 'label' => $labels[$row->{$column}] ?? ($row->{$column} ?: 'Chưa xác định'), 'total' => (int) $row->total, 'revenue' => (int) $row->revenue])->all();
    }

    private function filteredQuery(): Builder
    {
        return Order::query()->when(Filament::getTenant()?->getKey(), fn (Builder $q, $id) => $q->where('city_id', $id))->when($this->serviceType, fn (Builder $q) => $q->where('service_type', $this->serviceType))->when($this->platform, fn (Builder $q) => $q->where('platform', $this->platform))->when($this->paymentMethod, fn (Builder $q) => $q->where('payment_method', $this->paymentMethod));
    }

    private function completedQuery(Carbon $from, Carbon $to): Builder
    {
        return $this->filteredQuery()->where('status', 'completed')->whereBetween('completed_at', [$from, $to]);
    }

    private bool $rangeWasClamped = false;

    private function dateRange(): array
    {
        $this->rangeWasClamped = false;
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
        [$from, $to, $this->rangeWasClamped] = $this->clampRange($from, $to);

        return [$from, $to];
    }
}
