<?php
namespace Modules\Order\Services;

use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchService
{
    const CALLKIT_RING_SECS  = 60;
    const APP_DECISION_SECS  = 30;
    const MIN_WALLET_BALANCE = 100_000;

    // Trọng số điểm (tổng tối đa ~100 điểm bonus)
    const W_DISTANCE     = 45;  // max penalty từ khoảng cách
    const W_RATING       = 20;  // max bonus từ đánh giá
    const W_ACTIVITY     = 10;  // max bonus từ tần suất nhận đơn 30 ngày
    const W_ACCEPT_RATE  =  8;  // max bonus từ tỷ lệ chấp nhận đơn
    const RATING_MIN_COUNT = 3; // cần ít nhất 3 đánh giá mới tính điểm

    public function startDispatch(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $driver = $this->selectNextDriver($order);

        if (!$driver) {
            Log::warning("[Dispatch] No available driver for order #{$order->id} in city {$order->city_id}");
            return;
        }

        $this->offerToDriver($order, $driver);
    }

    public function handleTimeout(Order $order, int $driverId): void
    {
        $this->markLogResult($order->id, $driverId, 'expired');
        $this->clearDispatchingDriver($order);

        Log::info("[Dispatch] Offer expired: order #{$order->id} → driver #{$driverId}");
        $this->tryNextDriver($order->fresh());
    }

    public function handleDecline(Order $order, User $driver): void
    {
        $this->markLogResult($order->id, $driver->id, 'declined');
        $this->clearDispatchingDriver($order);

        Log::info("[Dispatch] Driver #{$driver->id} declined order #{$order->id}");
        $this->tryNextDriver($order->fresh());
    }

    public function handleAccepted(Order $order, User $driver): void
    {
        $this->markLogResult($order->id, $driver->id, 'accepted');

        Log::info("[Dispatch] Driver #{$driver->id} accepted order #{$order->id}");
    }

    private function tryNextDriver(Order $order): void
    {
        if (!$order || $order->status !== 'pending') return;

        $driver = $this->selectNextDriver($order);

        if (!$driver) {
            Log::warning("[Dispatch] No more drivers for order #{$order->id}");
            return;
        }

        $this->offerToDriver($order, $driver);
    }

    private function offerToDriver(Order $order, User $driver): void
    {
        DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'pending')
            ->update([
                'dispatching_to_driver_id' => $driver->id,
                'offer_viewed_at'          => null,
                'dispatch_attempts'        => DB::raw('dispatch_attempts + 1'),
                'updated_at'               => now(),
            ]);

        OrderDispatchLog::create([
            'order_id'   => $order->id,
            'driver_id'  => $driver->id,
            'offered_at' => now(),
            'result'     => 'pending',
        ]);

        $offeredAt = now()->toIso8601String();

        if ($driver->fcm_token) {
            try {
                FCMService::getInstance()->sendOrderOffer(
                    $driver->fcm_token,
                    $order->toArray(),
                    self::CALLKIT_RING_SECS,
                    $offeredAt
                );
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM failed for driver #{$driver->id}: " . $e->getMessage());
            }
        }

        DispatchOrderJob::dispatch($order->id, $driver->id, false)
            ->delay(now()->addSeconds(self::CALLKIT_RING_SECS + 3));

        Log::info("[Dispatch] Offered order #{$order->id} to driver #{$driver->id}");
    }

    public function viewOffer(Order $order, int $driverId): void
    {
        $updated = DB::table('orders')
            ->where('id', $order->id)
            ->where('dispatching_to_driver_id', $driverId)
            ->whereNull('offer_viewed_at')
            ->update(['offer_viewed_at' => now()]);

        if (!$updated) return;

        DispatchOrderJob::dispatch($order->id, $driverId, true)
            ->delay(now()->addSeconds(self::APP_DECISION_SECS + 2));

        Log::info("[Dispatch] Driver #{$driverId} opened offer screen for order #{$order->id}");
    }

    public function selectNextDriver(Order $order): ?User
    {
        $triedIds = OrderDispatchLog::where('order_id', $order->id)
            ->whereIn('result', ['declined', 'expired'])
            ->pluck('driver_id');

        $busyDriverIds = Order::whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereNotNull('delivery_man_id')
            ->pluck('delivery_man_id');

        $candidates = User::where('user_type', 'driver')
            ->where('city_id', $order->city_id)
            ->where('status', 1)
            ->where('is_online', true)
            ->whereNotIn('id', $triedIds)
            ->whereNotIn('id', $busyDriverIds)
            ->with(['debts', 'wallet'])
            ->get()
            ->filter(fn(User $d) => !$this->hasBlockedDebt($d))
            ->filter(fn(User $d) => $this->hasMinWalletBalance($d))
            ->filter(fn(User $d) => $order->service_type !== 'car' || $d->has_car_license);

        if ($candidates->isEmpty()) return null;

        $driverIds = $candidates->pluck('id')->all();

        // Precompute tất cả stats bằng 4 query thay vì N query
        $statsMap = $this->precomputeStats($driverIds);

        $scored = $candidates->map(function (User $d) use ($order, $statsMap) {
            $stats = $statsMap[$d->id] ?? [];

            // Tính khoảng cách riêng (cần object driver)
            if ($d->latitude && $d->longitude && $order->pickup_lat && $order->pickup_lng) {
                $stats['dist_km'] = $this->haversineKm(
                    (float) $d->latitude, (float) $d->longitude,
                    (float) $order->pickup_lat, (float) $order->pickup_lng
                );
            }

            $score = $this->score($d, $order, $stats);

            Log::debug(sprintf(
                "[Dispatch] Driver #%d score=%.1f | dist=%.1fkm | rating=%.1f(%d) | active=%d | completed30d=%d | accept=%.0f%%",
                $d->id, $score,
                $stats['dist_km'] ?? 0,
                $stats['avg_rating'] ?? 0, $stats['rating_count'] ?? 0,
                $stats['active'] ?? 0,
                $stats['completed_30d'] ?? 0,
                ($stats['offered_30d'] ?? 0) > 0
                    ? ($stats['accepted_30d'] / $stats['offered_30d']) * 100
                    : 0
            ));

            return ['driver' => $d, 'score' => $score];
        });

        return $scored->sortByDesc('score')->first()['driver'];
    }

    // ─── Scoring ─────────────────────────────────────────────────────────────

    private function score(User $driver, Order $order, array $stats): float
    {
        $score = 100.0;

        // 1. Khoảng cách (max penalty: -45) ─────────────────────────────────
        //    Tài xế trong vòng 1km mất -3 điểm, 15km+ mất -45 điểm
        $km = $stats['dist_km'] ?? null;
        if ($km !== null) {
            $score -= min($km * 3, self::W_DISTANCE);
        }

        // 2. Đang bận (mỗi đơn active: -20) ──────────────────────────────────
        $score -= ($stats['active'] ?? 0) * 20;

        // 3. Điểm đánh giá (0 đến +20) ───────────────────────────────────────
        //    5★ = +20, 4★ = +16, 3★ = +12, 2★ = +8, 1★ = +4
        //    Tài xế mới chưa đủ đánh giá → +10 (trung lập, không phân biệt)
        $avgRating    = $stats['avg_rating'] ?? null;
        $ratingCount  = $stats['rating_count'] ?? 0;
        if ($avgRating !== null && $ratingCount >= self::RATING_MIN_COUNT) {
            $score += ($avgRating / 5) * self::W_RATING;
        } else {
            $score += self::W_RATING / 2; // +10 cho tài xế mới
        }

        // 4. Tần suất hoạt động 30 ngày (0 đến +10) ──────────────────────────
        //    Mỗi 2 đơn hoàn thành = +1 điểm, tối đa +10 (20 đơn/tháng)
        $completed30d = $stats['completed_30d'] ?? 0;
        $score += min($completed30d / 2, self::W_ACTIVITY);

        // 5. Tỷ lệ chấp nhận đơn 30 ngày (0 đến +8) ──────────────────────────
        //    Chỉ tính khi đã nhận >= 5 lần phát đơn (tránh bias tài xế mới)
        //    Tỷ lệ 100% = +8, 50% = +4, dưới 30% bị phạt thêm -5
        $offered  = $stats['offered_30d'] ?? 0;
        $accepted = $stats['accepted_30d'] ?? 0;
        if ($offered >= 5) {
            $rate = $accepted / $offered;
            $score += $rate * self::W_ACCEPT_RATE;
            if ($rate < 0.3) $score -= 5; // phạt nếu từ chối quá nhiều
        }

        return $score;
    }

    // ─── Precompute stats (4 queries cho toàn bộ candidates) ─────────────────

    private function precomputeStats(array $driverIds): array
    {
        $since30d = now()->subDays(30);

        // Rating trung bình + số lượng
        $ratings = Order::whereIn('delivery_man_id', $driverIds)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->select(
                'delivery_man_id',
                DB::raw('AVG(driver_rating) as avg_rating'),
                DB::raw('COUNT(*) as rating_count')
            )
            ->groupBy('delivery_man_id')
            ->get()
            ->keyBy('delivery_man_id');

        // Số đơn hoàn thành 30 ngày gần nhất
        $activity = Order::whereIn('delivery_man_id', $driverIds)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since30d)
            ->select('delivery_man_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('delivery_man_id')
            ->pluck('cnt', 'delivery_man_id');

        // Tỷ lệ chấp nhận đơn 30 ngày (từ dispatch log)
        $dispatchLog = OrderDispatchLog::whereIn('driver_id', $driverIds)
            ->where('created_at', '>=', $since30d)
            ->select(
                'driver_id',
                DB::raw('COUNT(*) as offered'),
                DB::raw('SUM(result = "accepted") as accepted')
            )
            ->groupBy('driver_id')
            ->get()
            ->keyBy('driver_id');

        // Số đơn đang active
        $activeOrders = Order::whereIn('delivery_man_id', $driverIds)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->select('delivery_man_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('delivery_man_id')
            ->pluck('cnt', 'delivery_man_id');

        // Gộp lại thành map theo driver_id
        $map = [];
        foreach ($driverIds as $id) {
            $r = $ratings[$id] ?? null;
            $d = $dispatchLog[$id] ?? null;

            $map[$id] = [
                'avg_rating'    => $r ? round((float) $r->avg_rating, 2) : null,
                'rating_count'  => $r ? (int) $r->rating_count : 0,
                'completed_30d' => (int) ($activity[$id] ?? 0),
                'offered_30d'   => $d ? (int) $d->offered : 0,
                'accepted_30d'  => $d ? (int) $d->accepted : 0,
                'active'        => (int) ($activeOrders[$id] ?? 0),
                'dist_km'       => null, // sẽ tính riêng bên dưới
            ];
        }

        return $map;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function hasBlockedDebt(User $driver): bool
    {
        return $driver->debts->where('status', 'overdue')->isNotEmpty();
    }

    private function hasMinWalletBalance(User $driver): bool
    {
        return ($driver->wallet?->balance ?? 0) >= self::MIN_WALLET_BALANCE;
    }

    private function markLogResult(int $orderId, int $driverId, string $result): void
    {
        OrderDispatchLog::where('order_id', $orderId)
            ->where('driver_id', $driverId)
            ->where('result', 'pending')
            ->update(['result' => $result, 'responded_at' => now()]);
    }

    private function clearDispatchingDriver(Order $order): void
    {
        DB::table('orders')
            ->where('id', $order->id)
            ->update(['dispatching_to_driver_id' => null, 'updated_at' => now()]);
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
