<?php

namespace App\Filament\Pages;

use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Modules\Order\Models\Order;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RevenueReportPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Tổng quan';

    protected static ?string $navigationLabel = 'Báo cáo doanh thu';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.revenue-report';

    public string $from = '';

    public string $to = '';

    public string $serviceType = '';

    public string $platform = '';

    public string $paymentMethod = '';

    public string $period = 'this_month';

    private static array $serviceLabels = ['delivery' => 'Lấy đồ hộ', 'shopping' => 'Mua hộ', 'topup' => 'Nạp tiền', 'bike' => 'Xe ôm', 'motor' => 'Lái hộ xe máy', 'car' => 'Lái hộ ô tô'];

    private static array $platformLabels = ['customer_app' => 'Ứng dụng khách hàng', 'shop_app' => 'Ứng dụng cửa hàng', 'admin' => 'Quản trị viên', 'call_center' => 'Tổng đài'];

    private static array $paymentLabels = ['cash' => 'Tiền mặt', 'wallet' => 'Ví', 'bank_transfer' => 'Chuyển khoản', 'transfer' => 'Chuyển khoản'];

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
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            default => [now()->startOfMonth(), now()],
        };
        $this->period = $period;
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

    public function getFilterOptions(): array
    {
        return ['services' => self::$serviceLabels, 'platforms' => self::$platformLabels, 'payments' => self::$paymentLabels];
    }

    public function getReportData(): array
    {
        [$from, $to] = $this->dateRange();
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();
        $summary = $this->summaryForPeriod($from, $to);
        $previous = $this->summaryForPeriod($previousFrom, $previousTo);
        $summary['revenue_change'] = $this->percentChange($summary['total_revenue'], $previous['total_revenue']);
        $summary['orders_change'] = $this->percentChange($summary['completed_orders'], $previous['completed_orders']);
        $summary['completion_rate'] = $summary['total_orders'] ? round($summary['completed_orders'] / $summary['total_orders'] * 100, 1) : 0;

        return [
            'summary' => $summary,
            'trend' => $this->dailyTrend($from, $to),
            'services' => $this->breakdown('service_type', $from, $to, self::$serviceLabels),
            'platforms' => $this->breakdown('platform', $from, $to, self::$platformLabels),
            'payments' => $this->breakdown('payment_method', $from, $to, self::$paymentLabels),
            'from' => $from, 'to' => $to,
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
                    fputcsv($output, [$order->code ?: $order->id, optional($order->completed_at)->format('d/m/Y H:i'), self::$serviceLabels[$order->service_type] ?? $order->service_type, self::$platformLabels[$order->platform] ?? $order->platform, self::$paymentLabels[$order->payment_method] ?? $order->payment_method, (int) $order->shipping_fee, $surcharge, (int) $order->discount_amount, (int) $order->shipping_fee + $surcharge]);
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
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders")->first();
        $financial = $this->completedQuery($from, $to)
            ->selectRaw('COUNT(*) as completed_orders, COALESCE(SUM(shipping_fee), 0) as shipping_revenue')
            ->selectRaw('COALESCE(SUM(bonus_fee + night_surcharge), 0) as surcharge_revenue, COALESCE(SUM(discount_amount), 0) as total_discount')->first();
        $shipping = (int) $financial->shipping_revenue;
        $surcharge = (int) $financial->surcharge_revenue;
        $completed = (int) $financial->completed_orders;

        return ['total_orders' => (int) $operational->total_orders, 'completed_orders' => $completed, 'active_orders' => (int) $operational->active_orders, 'cancelled_orders' => (int) $operational->cancelled_orders, 'shipping_revenue' => $shipping, 'surcharge_revenue' => $surcharge, 'total_discount' => (int) $financial->total_discount, 'total_revenue' => $shipping + $surcharge, 'avg_fee' => $completed ? (int) round(($shipping + $surcharge) / $completed) : 0];
    }

    private function dailyTrend(Carbon $from, Carbon $to): array
    {
        $created = $this->filteredQuery()->whereBetween('created_at', [$from, $to])->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupBy('day')->pluck('total', 'day');
        $completed = $this->completedQuery($from, $to)->selectRaw('DATE(completed_at) as day, COUNT(*) as completed, COALESCE(SUM(shipping_fee + bonus_fee + night_surcharge), 0) as revenue, COALESCE(SUM(discount_amount), 0) as discount')->groupBy('day')->get()->keyBy('day');
        $rows = [];
        for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $financial = $completed->get($key);
            $rows[] = ['date' => $key, 'label' => $date->format('d/m'), 'total' => (int) ($created[$key] ?? 0), 'completed' => (int) ($financial?->completed ?? 0), 'revenue' => (int) ($financial?->revenue ?? 0), 'discount' => (int) ($financial?->discount ?? 0)];
        }

        return $rows;
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

    private function percentChange(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
