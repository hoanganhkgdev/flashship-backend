<?php
namespace Modules\Order\Services;

use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Jobs\AutoCancelOrderJob;
use Modules\Order\Jobs\DispatchOrderJob;
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
    const RADIUS_KM          = 5;    // bán kính tìm tài xế
    const DRIVER_OFFER_SECS  = 30;   // 30 giây mỗi tài xế
    const TIMEOUT_SECS       = 600;  // 10 phút → auto-cancel nếu không ai nhận
    const FCM_TTL_SECS       = 30;
    const MAX_DRIVERS        = 50;

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function startDispatch(Order $order): void
    {
        if ($order->status !== 'pending') return;

        RTDBService::publishPendingOrder($order);

        // Auto-cancel sau 10 phút nếu không có tài xế nào nhận
        AutoCancelOrderJob::dispatch($order->id)->delay(now()->addSeconds(self::TIMEOUT_SECS));

        $this->sendToNextDriver($order);
    }

    /**
     * Tìm tài xế tiếp theo (chưa được hỏi) và gửi đơn.
     * Nếu hết tài xế → hủy đơn ngay.
     */
    public function sendToNextDriver(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $alreadyOffered = OrderDispatchLog::where('order_id', $order->id)
            ->pluck('driver_id')
            ->toArray();

        $candidates = $this->getCandidates($order, self::RADIUS_KM, $alreadyOffered);

        if ($candidates->isEmpty()) {
            Log::info("[Dispatch] Order #{$order->id}: no more drivers → cancelling");
            $this->cancelIfNoDriver($order);
            return;
        }

        $this->sendToDriver($order, $candidates->first());
    }

    /**
     * Xử lý khi tài xế không phản hồi trong 30 giây.
     * Phạt điểm như từ chối rồi chuyển sang tài xế tiếp theo.
     */
    public function handleTimeout(Order $order, int $driverId): void
    {
        DriverScoreService::onDecline($driverId);

        OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', $driverId)
            ->where('result', 'pending')
            ->update(['result' => 'expired', 'responded_at' => now()]);

        Log::info("[Dispatch] Order #{$order->id}: driver #{$driverId} timed out → next driver");

        $this->sendToNextDriver($order->fresh());
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

        Log::info("[Dispatch] Order #{$order->id} auto-cancelled: no driver accepted");
    }

    public function handleAccepted(Order $order, User $driver): void
    {
        OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->where('result', 'pending')
            ->update(['result' => 'accepted', 'responded_at' => now()]);

        RTDBService::removePendingOrder($order->code, $order->city_id);

        Log::info("[Dispatch] Driver #{$driver->id} accepted order #{$order->id}");
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function sendToDriver(Order $order, User $driver): void
    {
        $now = now();

        OrderDispatchLog::create([
            'order_id'   => $order->id,
            'driver_id'  => $driver->id,
            'offered_at' => $now,
            'result'     => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('orders')->where('id', $order->id)->update([
            'dispatching_to_driver_id' => $driver->id,
            'dispatch_attempts'        => DB::raw('dispatch_attempts + 1'),
            'updated_at'               => $now,
        ]);

        if ($driver->fcm_token) {
            try {
                $orderData                   = $order->toArray();
                $orderData['customer_phone'] = $order->sender?->phone ?? '';
                FCMService::getInstance()->sendMulticastOrderOffer(
                    [$driver->fcm_token],
                    $orderData,
                    self::FCM_TTL_SECS,
                    $now->toIso8601String()
                );
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM failed for driver #{$driver->id}: " . $e->getMessage());
            }
        }

        DispatchOrderJob::dispatch($order->id, $driver->id)
            ->delay(now()->addSeconds(self::DRIVER_OFFER_SECS));

        Log::info("[Dispatch] Order #{$order->id} → Driver #{$driver->id} (score={$driver->driver_score})");
    }

    public function getCandidates(Order $order, float $radiusKm, array $excludeIds = []): Collection
    {
        $busyDriverIds = Order::whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereNotNull('delivery_man_id')
            ->pluck('delivery_man_id');

        $candidates = User::where('user_type', 'driver')
            ->where('city_id', $order->city_id)
            ->where('status', 1)
            ->where('is_online', true)
            ->whereNotIn('id', $busyDriverIds)
            ->whereNotIn('id', $excludeIds)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with(['debts', 'wallet', 'driverLicenses'])
            ->get();

        $driverIds = $candidates->pluck('id')->toArray();

        $ratings = Order::whereIn('delivery_man_id', $driverIds)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->select('delivery_man_id', DB::raw('AVG(driver_rating) as avg_rating'))
            ->groupBy('delivery_man_id')
            ->pluck('avg_rating', 'delivery_man_id');

        return $candidates
            ->filter(fn(User $d) => !$this->hasBlockedDebt($d))
            ->filter(fn(User $d) => $order->service_type !== 'car' || $d->has_car_license)
            ->filter(fn(User $d) => $this->distanceKm($d, $order) <= $radiusKm)
            ->sortByDesc(fn(User $d) => $this->compositeScore($d, $order, (float) ($ratings[$d->id] ?? 0)))
            ->take(self::MAX_DRIVERS)
            ->values();
    }

    private function compositeScore(User $driver, Order $order, float $avgRating): float
    {
        $dist        = $this->distanceKm($driver, $order);
        $distScore   = (1 - min($dist, self::RADIUS_KM) / self::RADIUS_KM) * 40;
        $ratingScore = ($avgRating / 5) * 30;
        $scoreScore  = (($driver->driver_score ?? 80) / 100) * 30;

        return $distScore + $ratingScore + $scoreScore;
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

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
