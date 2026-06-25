<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Modules\Core\Models\ServiceType;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchService;

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

    /**
     * Đơn đang trong quá trình phát (chưa có tài xế nhận).
     */
    private function serviceLabels(): array
    {
        static $cache = null;
        return $cache ??= ServiceType::pluck('label', 'key')->toArray();
    }

    public function getActiveOrders(): array
    {
        $query = Order::query()
            ->whereNotNull('dispatch_started_at')
            ->whereNull('delivery_man_id')
            ->whereIn('status', ['pending'])
            ->with('city')
            ->orderByDesc('dispatch_started_at')
            ->limit(30);

        $user = auth()->user();
        if (in_array($user?->user_type, ['city_manager', 'call_center']) && $user->city_id) {
            $query->where('city_id', $user->city_id);
        }

        return $query->get()
            ->map(function (Order $o) {
                $driverName = $o->dispatching_to_driver_id
                    ? DB::table('users')->where('id', $o->dispatching_to_driver_id)->value('name')
                    : null;

                $elapsedSecs = max(0, now()->getTimestamp() - \Carbon\Carbon::parse($o->dispatch_started_at)->getTimestamp());

                $maxRadius = DispatchService::MAX_RADIUS_KM;
                $isNoDriver = $o->cancel_reason === 'no_driver';
                $status = $isNoDriver
                    ? 'Không tìm được tài xế'
                    : ($driverName
                        ? "Đang chờ {$driverName}"
                        : "Đang quét 2-{$maxRadius}km...");

                return [
                    'id'           => $o->id,
                    'service_type' => $this->serviceLabels()[$o->service_type] ?? $o->service_type,
                    'city'         => $o->city?->name,
                    'elapsed'      => $elapsedSecs,
                    'attempts'     => $o->dispatch_attempts,
                    'radius'       => "2-{$maxRadius}km",
                    'offering_to'  => $driverName,
                    'status'       => $status,
                    'is_no_driver' => $isNoDriver,
                    'started_at'   => $o->dispatch_started_at,
                ];
            })
            ->toArray();
    }

    /**
     * Lịch sử offer gần đây (50 dòng cuối).
     */
    public function getRecentOffers(): array
    {
        return OrderDispatchLog::query()
            ->join('users', 'users.id', '=', 'order_dispatch_logs.driver_id')
            ->orderByDesc('order_dispatch_logs.id')
            ->limit(20)
            ->get([
                'order_dispatch_logs.order_id',
                'order_dispatch_logs.driver_id',
                'users.name as driver_name',
                'order_dispatch_logs.offered_at',
                'order_dispatch_logs.responded_at',
                'order_dispatch_logs.result',
            ])
            ->map(function ($r) {
                $responseSecs = $r->responded_at
                    ? abs(\Carbon\Carbon::parse($r->responded_at)->getTimestamp() - \Carbon\Carbon::parse($r->offered_at)->getTimestamp())
                    : null;

                return [
                    'order_id'     => $r->order_id,
                    'driver_name'  => $r->driver_name,
                    'offered_at'   => \Carbon\Carbon::parse($r->offered_at)->format('H:i:s d/m'),
                    'result'       => $r->result,
                    'response_sec' => $responseSecs,
                ];
            })
            ->toArray();
    }

    /**
     * Thống kê hôm nay.
     */
    public function getTodayStats(): array
    {
        $today = Order::query()
            ->whereDate('dispatch_started_at', now()->toDateString())
            ->whereNotNull('dispatch_started_at')
            ->get(['id', 'status', 'delivery_man_id', 'dispatch_attempts', 'cancel_reason', 'dispatch_started_at', 'updated_at']);

        $total       = $today->count();
        $accepted    = $today->whereNotNull('delivery_man_id')->count();
        $firstTry    = $today->whereNotNull('delivery_man_id')->where('dispatch_attempts', 1)->count();
        $noDriver    = $today->where('cancel_reason', 'no_driver')->count();

        $avgAttempts = $accepted > 0
            ? round($today->whereNotNull('delivery_man_id')->avg('dispatch_attempts'), 1)
            : 0;

        $avgWaitSecs = $accepted > 0
            ? round($today->whereNotNull('delivery_man_id')->avg(function (Order $o) {
                return abs(\Carbon\Carbon::parse($o->updated_at)->getTimestamp() - \Carbon\Carbon::parse($o->dispatch_started_at)->getTimestamp());
            }))
            : 0;

        return [
            'total'         => $total,
            'accepted'      => $accepted,
            'accept_rate'   => $total > 0 ? round($accepted / $total * 100) : 0,
            'first_try'     => $firstTry,
            'first_try_rate'=> $accepted > 0 ? round($firstTry / $accepted * 100) : 0,
            'no_driver'     => $noDriver,
            'avg_attempts'  => $avgAttempts,
            'avg_wait_secs' => $avgWaitSecs,
        ];
    }

    private function radiusForOrder(int $orderId): float
    {
        try {
            $cached = \Illuminate\Support\Facades\Redis::get("dispatch:radius:{$orderId}");
            return $cached ? (float) $cached : DispatchService::RADIUS_KM_STAGES[0];
        } catch (\Throwable) {
            return DispatchService::RADIUS_KM_STAGES[0];
        }
    }

    protected function getFormActions(): array { return []; }
}
