<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Order\Models\Order;

class RevenueReportPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Tổng quan';
    protected static ?string $navigationLabel = 'Báo cáo doanh thu';
    protected static ?string $title           = ' ';
    protected static ?int    $navigationSort  = 1;
    protected static string  $view            = 'filament.pages.revenue-report';

    public static function canAccess(): bool
    {
        return !in_array(auth()->user()?->user_type, ['city_manager', 'call_center']);
    }

    public string $from      = '';
    public string $to        = '';
    public string $city_id   = '';
    public string $groupBy   = 'service_type';

    private static array $serviceLabels = [
        'delivery' => 'Lấy đồ hộ',
        'shopping' => 'Mua hộ',
        'topup'    => 'Nạp tiền',
        'bike'     => 'Xe ôm',
        'motor'    => 'Lái hộ xe máy',
        'car'      => 'Lái hộ ô tô',
    ];

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to   = now()->toDateString();
    }

    public function getCities(): array
    {
        return City::where('is_active', true)->pluck('name', 'id')->toArray();
    }

    public function getSummary(): array
    {
        $q = $this->baseQuery();
        return [
            'total_orders'     => $q->count(),
            'completed_orders' => (clone $q)->where('status', 'completed')->count(),
            'cancelled_orders' => (clone $q)->where('status', 'cancelled')->count(),
            'total_revenue'    => (clone $q)->where('status', 'completed')->sum('shipping_fee'),
            'avg_fee'          => (int) (clone $q)->where('status', 'completed')->avg('shipping_fee'),
        ];
    }

    public function getByService(): array
    {
        return $this->baseQuery()
            ->select(
                'service_type',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled"),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN shipping_fee ELSE 0 END) as revenue"),
                DB::raw("AVG(CASE WHEN status = 'completed' THEN shipping_fee ELSE NULL END) as avg_fee")
            )
            ->groupBy('service_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'label'     => self::$serviceLabels[$r->service_type] ?? $r->service_type,
                'total'     => $r->total,
                'completed' => $r->completed,
                'cancelled' => $r->cancelled,
                'rate'      => $r->total > 0 ? round($r->completed / $r->total * 100) : 0,
                'revenue'   => number_format((int) $r->revenue, 0, ',', '.'),
                'avg_fee'   => number_format((int) $r->avg_fee, 0, ',', '.'),
            ])
            ->toArray();
    }

    public function getByCity(): array
    {
        return $this->baseQuery()
            ->select(
                'orders.city_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled"),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN shipping_fee ELSE 0 END) as revenue")
            )
            ->join('cities', 'cities.id', '=', 'orders.city_id', 'left')
            ->addSelect('cities.name as city_name')
            ->groupBy('orders.city_id', 'cities.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'city'      => $r->city_name ?? 'Không xác định',
                'total'     => $r->total,
                'completed' => $r->completed,
                'cancelled' => $r->cancelled,
                'rate'      => $r->total > 0 ? round($r->completed / $r->total * 100) : 0,
                'revenue'   => number_format((int) $r->revenue, 0, ',', '.'),
            ])
            ->toArray();
    }

    public function getByDay(): array
    {
        return $this->baseQuery()
            ->select(
                DB::raw("DATE(created_at) as day"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN shipping_fee ELSE 0 END) as revenue")
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day'     => \Carbon\Carbon::parse($r->day)->format('d/m'),
                'total'   => $r->total,
                'revenue' => number_format((int) $r->revenue, 0, ',', '.'),
            ])
            ->toArray();
    }

    private function baseQuery()
    {
        $q = Order::query()
            ->whereBetween('orders.created_at', [
                \Carbon\Carbon::parse($this->from)->startOfDay(),
                \Carbon\Carbon::parse($this->to)->endOfDay(),
            ]);

        if ($this->city_id) {
            $q->where('city_id', $this->city_id);
        }

        return $q;
    }

    protected function getFormActions(): array { return []; }
}
