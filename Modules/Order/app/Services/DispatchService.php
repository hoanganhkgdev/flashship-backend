<?php
namespace Modules\Order\Services;

use Modules\Order\Events\OrderOfferedEvent;
use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchService
{
    const CALLKIT_RING_SECS  = 30; // CallKit rings for this long; job fires at +3s if no view
    const APP_DECISION_SECS  = 20; // driver has this long once the offer screen opens

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

        // Broadcast qua Reverb WebSocket (realtime, không cần polling)
        // Reverb: foreground realtime offer. TTL = ring duration cho foreground case.
        broadcast(new OrderOfferedEvent($driver->id, $order->toArray(), self::CALLKIT_RING_SECS, $offeredAt));

        if ($driver->fcm_token) {
            FCMService::getInstance()->sendOrderOffer(
                $driver->fcm_token,
                $order->toArray(),
                self::CALLKIT_RING_SECS,
                $offeredAt
            );
        }

        // Timeout job: nếu tài xế không mở app trong CALLKIT_RING_SECS giây thì chuyển sang tài xế tiếp theo.
        DispatchOrderJob::dispatch($order->id, $driver->id, false)
            ->delay(now()->addSeconds(self::CALLKIT_RING_SECS + 3));

        Log::info("[Dispatch] Offered order #{$order->id} to driver #{$driver->id}");
    }

    // Called when driver opens the OrderOfferScreen in the app.
    // Starts the app-decision timer — backend countdown begins NOW.
    public function viewOffer(Order $order, int $driverId): void
    {
        $updated = DB::table('orders')
            ->where('id', $order->id)
            ->where('dispatching_to_driver_id', $driverId)
            ->whereNull('offer_viewed_at')
            ->update(['offer_viewed_at' => now()]);

        if (!$updated) return; // already viewed or no longer offered to this driver

        DispatchOrderJob::dispatch($order->id, $driverId, true)
            ->delay(now()->addSeconds(self::APP_DECISION_SECS + 2));

        Log::info("[Dispatch] Driver #{$driverId} opened offer screen for order #{$order->id}");
    }

    public function selectNextDriver(Order $order): ?User
    {
        $triedIds = OrderDispatchLog::where('order_id', $order->id)
            ->whereIn('result', ['declined', 'expired'])
            ->pluck('driver_id');

        // Lấy danh sách tài xế đang có đơn active để loại ra
        $busyDriverIds = Order::whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereNotNull('delivery_man_id')
            ->pluck('delivery_man_id');

        $candidates = User::where('user_type', 'driver')
            ->where('city_id', $order->city_id)
            ->where('status', 1)
            ->where('is_online', true)
            ->whereNotIn('id', $triedIds)
            ->whereNotIn('id', $busyDriverIds)
            ->with(['debts'])
            ->get()
            ->filter(fn(User $d) => !$this->hasBlockedDebt($d))
            ->filter(fn(User $d) => $order->service_type !== 'car' || $d->has_car_license);

        if ($candidates->isEmpty()) return null;

        return $candidates
            ->map(fn(User $d) => ['driver' => $d, 'score' => $this->score($d, $order)])
            ->sortByDesc('score')
            ->first()['driver'];
    }

    private function score(User $driver, Order $order): float
    {
        $score = 100.0;

        $active = Order::where('delivery_man_id', $driver->id)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->count();
        $score -= $active * 20;

        if ($driver->latitude && $driver->longitude && $order->pickup_lat && $order->pickup_lng) {
            $km = $this->haversineKm(
                (float) $driver->latitude, (float) $driver->longitude,
                (float) $order->pickup_lat, (float) $order->pickup_lng
            );
            $score -= min($km * 3, 45);
        }

        return $score;
    }

    private function hasBlockedDebt(User $driver): bool
    {
        return $driver->debts->where('status', 'overdue')->isNotEmpty();
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
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
