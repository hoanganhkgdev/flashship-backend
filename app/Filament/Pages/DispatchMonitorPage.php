<?php

namespace App\Filament\Pages;

use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Modules\Core\Models\ServiceType;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;

class DispatchMonitorPage extends Page
{
    public static function canAccess(): bool
    {
        return !auth()->user()?->isCallCenter();
    }

    protected static ?string $navigationIcon  = 'heroicon-o-signal';
    protected static ?string $navigationGroup = 'Đơn hàng';
    protected static ?string $navigationLabel = 'Theo dõi phát đơn';
    protected static ?int    $navigationSort  = 50;
    protected static string  $view            = 'filament.pages.dispatch-monitor';

    public function getHeading(): string { return ''; }

    #[On('echo:dispatch-monitor,.state.changed')]
    public function refresh(): void {}

    private function serviceLabels(): array
    {
        static $cache = null;
        return $cache ??= ServiceType::pluck('label', 'key')->toArray();
    }

    /** Màu + nhãn cho từng kết quả offer — khớp đúng 4 giá trị thật của enum order_dispatch_logs.result. */
    public static function offerResultConfig(string $result): array
    {
        return match ($result) {
            'accepted' => ['color' => '#22c55e', 'label' => 'Nhận'],
            'declined' => ['color' => '#ef4444', 'label' => 'Từ chối'],
            'expired'  => ['color' => '#9ca3af', 'label' => 'Hết hạn'],
            default    => ['color' => '#f59e0b', 'label' => 'Đang chờ'], // 'pending'
        };
    }

    /** Luôn khoá theo đúng khu vực (tenant) đang đứng — đổi khu vực thì dùng bộ chuyển tenant trên topbar. */
    private function effectiveCityId(): ?int
    {
        return \Filament\Facades\Filament::getTenant()?->id;
    }

    /**
     * Đơn đang trong quá trình phát (chưa có tài xế nhận).
     */
    public function getActiveOrders(): array
    {
        $query = Order::query()
            ->whereNotNull('dispatch_started_at')
            ->whereNull('delivery_man_id')
            ->where('status', 'pending')
            ->with('city')
            ->orderByDesc('dispatch_started_at')
            ->limit(30);

        if ($cityId = $this->effectiveCityId()) {
            $query->where('city_id', $cityId);
        }

        $orders = $query->get();

        // Tên tài xế đang được hỏi — 1 query cho cả trang thay vì N+1
        $driverNames = DB::table('users')
            ->whereIn('id', $orders->pluck('dispatching_to_driver_id')->filter())
            ->pluck('name', 'id');

        return $orders->map(function (Order $o) use ($driverNames) {
            $driverName  = $driverNames[$o->dispatching_to_driver_id] ?? null;
            $elapsedSecs = max(0, now()->getTimestamp() - Carbon::parse($o->dispatch_started_at)->getTimestamp());

            $status = $driverName
                ? "Đang chờ {$driverName}"
                : "Đang quét toàn thành phố...";

            return [
                'id'           => $o->id,
                'service_type' => $this->serviceLabels()[$o->service_type] ?? $o->service_type,
                'city'         => $o->city?->name,
                'elapsed'      => $elapsedSecs,
                'attempts'     => $o->dispatch_attempts,
                'offering_to'  => $driverName,
                'status'       => $status,
                'started_at'   => $o->dispatch_started_at,
            ];
        })->toArray();
    }

    /**
     * Lực lượng tài xế lúc này — trả lời "đơn treo vì đâu" trong một cái liếc.
     */
    public function getDriverSupply(): array
    {
        $cityId = $this->effectiveCityId();

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->where('is_online', true)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->get(['id']);

        $ids = $drivers->pluck('id');

        // "Dead" = không có GPS/heartbeat đủ mới trên Firebase (nguồn duy
        // nhất, thay cho cột last_heartbeat_at đã ngừng được cập nhật định
        // kỳ từ khi bỏ cron sync GPS).
        $freshLocations = app(DriverLocationService::class)->freshLocationsFor($ids->all());

        // Số đơn active theo tài xế (assigned/processing/on_the_way)
        $activeCounts = DB::table('orders')
            ->whereIn('delivery_man_id', $ids)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COUNT(*) as cnt')
            ->pluck('cnt', 'delivery_man_id');

        $holdingOffer = DB::table('orders')
            ->where('status', 'pending')
            ->whereIn('dispatching_to_driver_id', $ids)
            ->pluck('dispatching_to_driver_id')
            ->flip();

        $online = $drivers->count();
        $dead = $busy1 = $busy2 = $holding = $ready = 0;

        foreach ($drivers as $d) {
            $hbDead = !isset($freshLocations[$d->id]);
            $active = (int) ($activeCounts[$d->id] ?? 0);

            if ($hbDead)                        { $dead++; continue; }
            if ($active >= 2)                   { $busy2++; continue; }
            if (isset($holdingOffer[$d->id]))   { $holding++; continue; }
            if ($active === 1)                  { $busy1++; continue; }
            $ready++;
        }

        return [
            'online'      => $online,
            'ready'       => $ready,
            'busy1'       => $busy1,
            'busy2'       => $busy2,
            'holding'     => $holding,
            'dead'        => $dead,
        ];
    }

    /**
     * Lịch sử offer gần đây.
     */
    public function getRecentOffers(): array
    {
        $cityId = $this->effectiveCityId();

        return OrderDispatchLog::query()
            ->join('users', 'users.id', '=', 'order_dispatch_logs.driver_id')
            ->join('orders', 'orders.id', '=', 'order_dispatch_logs.order_id')
            ->when($cityId, fn ($q) => $q->where('orders.city_id', $cityId))
            ->orderByDesc('order_dispatch_logs.id')
            ->limit(20)
            ->get([
                'order_dispatch_logs.id',
                'order_dispatch_logs.order_id',
                'users.name as driver_name',
                'order_dispatch_logs.offered_at',
                'order_dispatch_logs.responded_at',
                'order_dispatch_logs.result',
            ])
            ->map(function ($r) {
                $responseSecs = $r->responded_at
                    ? abs(Carbon::parse($r->responded_at)->getTimestamp() - Carbon::parse($r->offered_at)->getTimestamp())
                    : null;

                return [
                    'id'           => $r->id,
                    'order_id'     => $r->order_id,
                    'driver_name'  => $r->driver_name,
                    'offered_at'   => Carbon::parse($r->offered_at)->format('H:i:s d/m'),
                    'result'       => $r->result,
                    'response_sec' => $responseSecs,
                ];
            })
            ->toArray();
    }

    /**
     * Thống kê phát đơn hôm nay.
     */
    public function getTodayStats(): array
    {
        $cityId = $this->effectiveCityId();

        $agg = DB::table('orders')
            ->whereDate('dispatch_started_at', now()->toDateString())
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->selectRaw('COUNT(*) as total,
                SUM(delivery_man_id IS NOT NULL) as accepted,
                SUM(delivery_man_id IS NOT NULL AND dispatch_attempts = 1) as first_try,
                SUM(cancel_reason = "no_driver") as no_driver,
                AVG(CASE WHEN delivery_man_id IS NOT NULL THEN dispatch_attempts END) as avg_attempts')
            ->first();

        // Thời gian chờ thật: từ lúc bắt đầu phát đến lúc tài xế bấm nhận
        // (log accepted) — không dùng orders.updated_at vì nó đổi theo mọi cập nhật.
        $avgWait = DB::table('orders as o')
            ->join('order_dispatch_logs as l', function ($j) {
                $j->on('l.order_id', '=', 'o.id')
                  ->on('l.driver_id', '=', 'o.delivery_man_id')
                  ->where('l.result', 'accepted');
            })
            ->whereDate('o.dispatch_started_at', now()->toDateString())
            ->when($cityId, fn ($q) => $q->where('o.city_id', $cityId))
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, o.dispatch_started_at, l.responded_at)) as w')
            ->value('w');

        $total    = (int) ($agg->total ?? 0);
        $accepted = (int) ($agg->accepted ?? 0);

        return [
            'total'          => $total,
            'accepted'       => $accepted,
            'accept_rate'    => $total > 0 ? round($accepted / $total * 100) : 0,
            'first_try'      => (int) ($agg->first_try ?? 0),
            'first_try_rate' => $accepted > 0 ? round(($agg->first_try ?? 0) / $accepted * 100) : 0,
            'no_driver'      => (int) ($agg->no_driver ?? 0),
            'avg_attempts'   => round((float) ($agg->avg_attempts ?? 0), 1),
            'avg_wait_secs'  => (int) round((float) ($avgWait ?? 0)),
        ];
    }

    protected function getFormActions(): array { return []; }
}
