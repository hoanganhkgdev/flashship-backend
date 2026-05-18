<?php
namespace Modules\Order\Services;

use Modules\Order\Jobs\BroadcastTimeoutJob;
use Modules\Order\Jobs\ExpandBroadcastJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchService
{
    const BROADCAST_RADIUS_1 = 5;    // km — bán kính phát lần đầu
    const BROADCAST_RADIUS_2 = 10;   // km — mở rộng sau 2 phút
    const EXPAND_AFTER_SECS  = 120;  // 2 phút → mở rộng bán kính
    const TIMEOUT_SECS       = 300;  // 5 phút → auto-cancel
    const FCM_TTL_SECS       = 30;   // TTL của FCM offer
    const MAX_DRIVERS        = 20;   // Tối đa 20 tài xế mỗi lần broadcast
    const MIN_WALLET_BALANCE = 100_000;

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function startDispatch(Order $order): void
    {
        if ($order->status !== 'pending') return;

        // Đăng lên RTDB để tài xế thấy order trên bản đồ
        RTDBService::publishPendingOrder($order);

        $drivers = $this->getCandidates($order, self::BROADCAST_RADIUS_1);

        if ($drivers->isEmpty()) {
            $drivers = $this->getCandidates($order, self::BROADCAST_RADIUS_2);
        }

        if ($drivers->isNotEmpty()) {
            $this->broadcastToDrivers($order, $drivers);
        } else {
            Log::warning("[Dispatch] No drivers for order #{$order->id} in city {$order->city_id}");
        }

        // Mở rộng bán kính sau 2 phút (dù có driver hay không, phòng trường hợp chưa ai nhận)
        ExpandBroadcastJob::dispatch($order->id)
            ->delay(now()->addSeconds(self::EXPAND_AFTER_SECS));

        // Auto-cancel sau 5 phút nếu không ai nhận
        BroadcastTimeoutJob::dispatch($order->id)
            ->delay(now()->addSeconds(self::TIMEOUT_SECS));
    }

    public function expandBroadcast(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $alreadyOffered = OrderDispatchLog::where('order_id', $order->id)
            ->pluck('driver_id')
            ->toArray();

        $drivers = $this->getCandidates($order, self::BROADCAST_RADIUS_2, $alreadyOffered);

        if ($drivers->isEmpty()) {
            Log::info("[Dispatch] Expand: no new drivers for order #{$order->id}");
            return;
        }

        Log::info("[Dispatch] Expand to {$drivers->count()} more drivers for order #{$order->id}");
        $this->broadcastToDrivers($order, $drivers);
    }

    public function cancelIfNoDriver(Order $order): void
    {
        if ($order->status !== 'pending') return;

        OrderDispatchLog::where('order_id', $order->id)
            ->where('result', 'pending')
            ->update(['result' => 'expired', 'responded_at' => now()]);

        $cancelled = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'cancel_reason' => 'no_driver', 'updated_at' => now()]);

        if (!$cancelled) return;

        RTDBService::removePendingOrder($order->code, $order->city_id);

        $customer = User::find($order->sender_platform_id);
        if ($customer?->fcm_token) {
            FCMService::getInstance()->sendNoDriverCancellation($customer->fcm_token, $order->code);
        }

        Log::info("[Dispatch] Order #{$order->id} auto-cancelled: no driver accepted in 5 min");
    }

    public function handleAccepted(Order $order, User $driver): void
    {
        OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->where('result', 'pending')
            ->update(['result' => 'accepted', 'responded_at' => now()]);

        // Hủy offer với các tài xế còn lại
        $otherDriverIds = OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', '!=', $driver->id)
            ->where('result', 'pending')
            ->pluck('driver_id');

        if ($otherDriverIds->isNotEmpty()) {
            OrderDispatchLog::where('order_id', $order->id)
                ->whereIn('driver_id', $otherDriverIds)
                ->update(['result' => 'expired', 'responded_at' => now()]);

            $tokens = User::whereIn('id', $otherDriverIds)
                ->whereNotNull('fcm_token')
                ->pluck('fcm_token')
                ->toArray();

            foreach ($tokens as $token) {
                FCMService::getInstance()->sendOrderTakenByOther($token, $order->code);
            }
        }

        RTDBService::removePendingOrder($order->code, $order->city_id);

        Log::info("[Dispatch] Driver #{$driver->id} accepted order #{$order->id}");
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function broadcastToDrivers(Order $order, Collection $drivers): void
    {
        $now       = now();
        $offeredAt = $now->toIso8601String();

        // Insert dispatch logs
        OrderDispatchLog::insert($drivers->map(fn($d) => [
            'order_id'   => $order->id,
            'driver_id'  => $d->id,
            'offered_at' => $now,
            'result'     => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ])->toArray());

        DB::table('orders')
            ->where('id', $order->id)
            ->update(['dispatch_attempts' => DB::raw('dispatch_attempts + 1'), 'updated_at' => $now]);

        // FCM multicast
        $tokens = $drivers->filter(fn($d) => $d->fcm_token)->pluck('fcm_token')->toArray();
        if (!empty($tokens)) {
            try {
                $orderData = $order->toArray();
                $orderData['customer_phone'] = $order->sender?->phone ?? '';
                FCMService::getInstance()->sendMulticastOrderOffer($tokens, $orderData, self::FCM_TTL_SECS, $offeredAt);
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM multicast failed: " . $e->getMessage());
            }
        }

        Log::info("[Dispatch] Broadcast order #{$order->id} → {$drivers->count()} drivers");
    }

    public function getCandidates(Order $order, float $radiusKm, array $excludeIds = []): Collection
    {
        $busyDriverIds = Order::whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereNotNull('delivery_man_id')
            ->pluck('delivery_man_id');

        return User::where('user_type', 'driver')
            ->where('city_id', $order->city_id)
            ->where('status', 1)
            ->where('is_online', true)
            ->whereNotIn('id', $busyDriverIds)
            ->whereNotIn('id', $excludeIds)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with(['debts', 'wallet'])
            ->get()
            ->filter(fn(User $d) => !$this->hasBlockedDebt($d))
            ->filter(fn(User $d) => $this->hasMinWalletBalance($d))
            ->filter(fn(User $d) => $order->service_type !== 'car' || $d->has_car_license)
            ->filter(fn(User $d) => $this->distanceKm($d, $order) <= $radiusKm)
            ->sortBy(fn(User $d) => $this->distanceKm($d, $order))
            ->take(self::MAX_DRIVERS)
            ->values();
    }

    private function distanceKm(User $driver, Order $order): float
    {
        return $this->haversineKm(
            (float) $driver->latitude,  (float) $driver->longitude,
            (float) $order->pickup_lat, (float) $order->pickup_lng
        );
    }

    private function hasBlockedDebt(User $driver): bool
    {
        return $driver->debts->where('status', 'overdue')->isNotEmpty();
    }

    private function hasMinWalletBalance(User $driver): bool
    {
        return ($driver->wallet?->balance ?? 0) >= self::MIN_WALLET_BALANCE;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
