<?php
namespace Modules\Order\Services;

use Modules\Driver\Services\DriverScoreService;
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
    const BROADCAST_RADIUS_1   = 5;    // km — đợt 1: score >= 60
    const BROADCAST_RADIUS_2   = 10;   // km — đợt 2: score >= 30, sau 2 phút
    const BROADCAST_RADIUS_3   = 10;   // km — đợt 3: score < 30, sau 4 phút
    const EXPAND_AFTER_SECS    = 120;  // 2 phút → đợt 2
    const EXPAND2_AFTER_SECS   = 240;  // 4 phút → đợt 3
    const TIMEOUT_SECS         = 300;  // 5 phút → auto-cancel
    const FCM_TTL_SECS         = 30;
    const MAX_DRIVERS          = 20;
    const MIN_WALLET_BALANCE   = 100_000;

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function startDispatch(Order $order): void
    {
        if ($order->status !== 'pending') return;

        RTDBService::publishPendingOrder($order);

        // Đợt 1: tài xế score >= 60, trong 5km
        $wave1 = $this->getCandidates($order, self::BROADCAST_RADIUS_1, [], DriverScoreService::WAVE_1_MIN);

        if ($wave1->isNotEmpty()) {
            $this->broadcastToDrivers($order, $wave1, 1);
        } else {
            Log::info("[Dispatch] Wave 1 empty for order #{$order->id}, will try wave 2");
        }

        ExpandBroadcastJob::dispatch($order->id, 2)->delay(now()->addSeconds(self::EXPAND_AFTER_SECS));
        ExpandBroadcastJob::dispatch($order->id, 3)->delay(now()->addSeconds(self::EXPAND2_AFTER_SECS));
        BroadcastTimeoutJob::dispatch($order->id)->delay(now()->addSeconds(self::TIMEOUT_SECS));
    }

    public function expandBroadcast(Order $order, int $wave): void
    {
        if ($order->status !== 'pending') return;

        $alreadyOffered = OrderDispatchLog::where('order_id', $order->id)->pluck('driver_id')->toArray();

        [$minScore, $radius] = match ($wave) {
            2 => [DriverScoreService::WAVE_2_MIN, self::BROADCAST_RADIUS_2],
            3 => [0,                              self::BROADCAST_RADIUS_3],
            default => [DriverScoreService::WAVE_1_MIN, self::BROADCAST_RADIUS_1],
        };

        $drivers = $this->getCandidates($order, $radius, $alreadyOffered, $minScore, $wave === 3 ? DriverScoreService::WAVE_2_MIN - 1 : null);

        if ($drivers->isEmpty()) {
            Log::info("[Dispatch] Wave {$wave} empty for order #{$order->id}");
            return;
        }

        $this->broadcastToDrivers($order, $drivers, $wave);
    }

    public function cancelIfNoDriver(Order $order): void
    {
        if ($order->status !== 'pending') return;

        // Trừ điểm tài xế đã được offer nhưng không phản hồi
        $pendingDriverIds = OrderDispatchLog::where('order_id', $order->id)
            ->where('result', 'pending')
            ->pluck('driver_id');

        foreach ($pendingDriverIds as $driverId) {
            DriverScoreService::onTimeout($driverId);
        }

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

    private function broadcastToDrivers(Order $order, Collection $drivers, int $wave): void
    {
        $now       = now();
        $offeredAt = $now->toIso8601String();

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

        $tokens = $drivers->filter(fn($d) => $d->fcm_token)->pluck('fcm_token')->toArray();
        if (!empty($tokens)) {
            try {
                $orderData = $order->toArray();
                $orderData['customer_phone'] = $order->sender?->phone ?? '';
                FCMService::getInstance()->sendMulticastOrderOffer($tokens, $orderData, self::FCM_TTL_SECS, $offeredAt);
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM multicast wave {$wave} failed: " . $e->getMessage());
            }
        }

        Log::info("[Dispatch] Wave {$wave} — order #{$order->id} → {$drivers->count()} drivers");
    }

    /**
     * Lấy danh sách tài xế ứng viên, lọc theo bán kính, điểm và sắp xếp theo composite score.
     *
     * @param int|null $maxScore  Giới hạn trên của driver_score (dùng cho đợt 3 chỉ lấy score thấp)
     */
    public function getCandidates(Order $order, float $radiusKm, array $excludeIds = [], int $minScore = 0, ?int $maxScore = null): Collection
    {
        $busyDriverIds = Order::whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereNotNull('delivery_man_id')
            ->pluck('delivery_man_id');

        $query = User::where('user_type', 'driver')
            ->where('city_id', $order->city_id)
            ->where('status', 1)
            ->where('is_online', true)
            ->where('driver_score', '>=', $minScore)
            ->whereNotIn('id', $busyDriverIds)
            ->whereNotIn('id', $excludeIds)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with(['debts', 'wallet', 'driverLicenses']);

        if ($maxScore !== null) {
            $query->where('driver_score', '<=', $maxScore);
        }

        // Precompute avg_rating để dùng trong composite score
        $candidates = $query->get();
        $driverIds  = $candidates->pluck('id')->toArray();

        $ratings = Order::whereIn('delivery_man_id', $driverIds)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->select('delivery_man_id', DB::raw('AVG(driver_rating) as avg_rating'))
            ->groupBy('delivery_man_id')
            ->pluck('avg_rating', 'delivery_man_id');

        return $candidates
            ->filter(fn(User $d) => !$this->hasBlockedDebt($d))
            ->filter(fn(User $d) => $this->hasMinWalletBalance($d))
            ->filter(fn(User $d) => $order->service_type !== 'car' || $d->has_car_license)
            ->filter(fn(User $d) => $this->distanceKm($d, $order) <= $radiusKm)
            ->sortByDesc(fn(User $d) => $this->compositeScore($d, $order, (float) ($ratings[$d->id] ?? 0)))
            ->take(self::MAX_DRIVERS)
            ->values();
    }

    /**
     * Composite score để sắp xếp ưu tiên trong cùng một đợt.
     * Tài xế đầu danh sách nhận FCM trước (quan trọng khi FCM delay).
     *
     * Gần (40%) + Đánh giá (30%) + Điểm uy tín (30%)
     */
    private function compositeScore(User $driver, Order $order, float $avgRating): float
    {
        $dist     = $this->distanceKm($driver, $order);
        $maxDist  = self::BROADCAST_RADIUS_2;

        $distScore   = (1 - min($dist, $maxDist) / $maxDist) * 40;
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
