<?php
namespace Modules\Order\Services;

use Carbon\Carbon;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Jobs\DispatchOrderRetryJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;
use Illuminate\Support\Collection;
use App\Events\DispatchStateChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Services\DriverGeoService;

class DispatchService
{
    // Bán kính mở rộng theo thứ tự — chuyển sang bán kính tiếp theo khi queue cạn,
    // không phụ thuộc thời gian nữa (queue-based thay vì time-based).
    const RADIUS_KM_STAGES = [2.0, 4.0];

    const DRIVER_OFFER_SECS  = 25;   // giây để mở app (trước khi offer_viewed_at set)
    const APP_DECISION_SECS  = 30;   // giây để đọc & quyết định SAU KHI mở app (như ShopeeFood)
    const FCM_TTL_SECS       = 25;
    const MAX_DRIVERS        = 50;
    const QUEUE_TTL_SECS     = 1200; // 20 phút — phải lớn hơn DISPATCH_TIMEOUT_MINS (15 phút)

    // Trọng số xếp hạng
    const W_SCORE         = 15;
    const W_RATING_CNT    = 10;
    const W_WAIT_TIME     = 50;
    const W_DISTANCE      = 25;

    const WAIT_TIME_CAP_MINS = 480; // 8 tiếng — tài xế chờ lâu được ưu tiên rõ hơn
    const RATING_COUNT_CAP   = 200;
    const MAX_RADIUS_KM      = 4.0;
    const MAX_DETOUR_KM      = 2.0;

    // =========================================================================
    // REDIS HELPERS
    // =========================================================================

    private function radiusKey(int $orderId): string
    {
        return "dispatch:radius:{$orderId}";
    }

    private function retryKey(int $orderId): string
    {
        return "dispatch:retry_pending:{$orderId}";
    }

    private function getCurrentRadius(int $orderId): float
    {
        return (float) (Redis::get($this->radiusKey($orderId)) ?? self::RADIUS_KM_STAGES[0]);
    }

    private function clearDispatchCache(int $orderId): void
    {
        Redis::del($this->radiusKey($orderId));
        Redis::del($this->retryKey($orderId));
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function startDispatch(Order $order): void
    {
        if ($order->status !== 'pending') return;

        if (!$order->pickup_lat || !$order->pickup_lng) {
            Log::warning("[Dispatch] Đơn #{$order->id} thiếu toạ độ pickup → không dispatch");
            return;
        }

        if ($order->dispatching_to_driver_id !== null) {
            Log::info("[Dispatch] Đơn #{$order->id} đang chờ tài xế #{$order->dispatching_to_driver_id} → bỏ qua restart");
            return;
        }

        $now = now();

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] BẮT ĐẦU PHÁT ĐƠN");
        Log::info("║  Đơn     : #{$order->id} | Mã: {$order->code}");
        Log::info("║  Loại    : {$order->service_type}");
        Log::info("║  Thành phố: {$order->city_id}");
        Log::info("║  Pickup  : {$order->pickup_address} ({$order->pickup_lat}, {$order->pickup_lng})");
        Log::info("╚══════════════════════════════════════════════════════════════");

        DB::table('orders')->where('id', $order->id)->update([
            'dispatch_started_at' => $order->dispatch_started_at ?? $now,
            'cancel_reason'       => null,
        ]);
        $order->dispatch_started_at = $order->dispatch_started_at ?? $now;
        $order->cancel_reason       = null;

        $this->notifyCustomer($order, 'searching');

        $this->offerToNext($order, self::RADIUS_KM_STAGES[0]);
    }

    public function sendToNextDriver(Order $order): void
    {
        if ($order->status !== 'pending') return;
        $radiusKm = $this->getCurrentRadius($order->id);
        $this->offerToNext($order, $radiusKm);
    }

    public function handleTimeout(Order $order, int $driverId): void
    {
        $driver = User::find($driverId);
        $name   = $driver?->name ?? "#{$driverId}";

        $updated = DB::transaction(function () use ($order, $driverId) {
            return OrderDispatchLog::where('order_id', $order->id)
                ->where('driver_id', $driverId)
                ->where('result', 'pending')
                ->lockForUpdate()
                ->update(['result' => 'expired', 'responded_at' => now()]);
        });

        if (!$updated) {
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} đã xử lý trước (decline/accept) → bỏ qua timeout");
            return;
        }

        Redis::del("dispatch:lock:driver:{$driverId}");

        if ($order->offer_viewed_at) {
            DriverScoreService::onDecline($driverId);
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} xem đơn nhưng không nhận → -2 điểm, pop tiếp");
        } else {
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} không xem đơn → 0 điểm, pop tiếp");
        }

        RTDBService::clearDriverOffer($driverId);

        $this->sendToNextDriver($order->fresh());
    }

    public function handleAccepted(Order $order, User $driver): void
    {
        $updated = DB::transaction(function () use ($order, $driver) {
            return OrderDispatchLog::where('order_id', $order->id)
                ->where('driver_id', $driver->id)
                ->where('result', 'pending')
                ->lockForUpdate()
                ->update(['result' => 'accepted', 'responded_at' => now()]);
        });

        if (!$updated) {
            Log::info("[Dispatch] Đơn #{$order->id}: Tài xế #{$driver->id} accept nhưng log đã đổi (timeout race) → bỏ qua");
            return;
        }

        RTDBService::clearDriverOffer($driver->id);
        $this->clearDispatchCache($order->id);

        $attempts = OrderDispatchLog::where('order_id', $order->id)->count();

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] KẾT QUẢ: ĐƠN #{$order->id} ĐƯỢC NHẬN");
        Log::info("║  Tài xế  : #{$driver->id} {$driver->name} | SĐT: {$driver->phone}");
        Log::info("║  Sau lần thử: #{$attempts}");
        Log::info("╚══════════════════════════════════════════════════════════════");
        broadcast(new DispatchStateChanged());
    }

    // =========================================================================
    // PRIVATE — DISPATCH CONTROL FLOW
    // =========================================================================

    public function markNoDriver(Order $order): void
    {
        $this->cancelNoDriver($order);
    }

    private function cancelNoDriver(Order $order): void
    {
        // Giữ đơn ở trạng thái pending, không hủy
        // Chỉ dừng dispatch và thông báo cho admin xử lý thủ công
        $updated = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'pending')
            ->update([
                'dispatching_to_driver_id' => null,
                'cancel_reason'            => 'no_driver',
                'updated_at'               => now(),
            ]);

        if (!$updated) return;

        $this->clearDispatchCache($order->id);
        broadcast(new DispatchStateChanged());

        $admins = User::whereIn('user_type', ['admin', 'call_center', 'city_manager'])
            ->where('city_id', $order->city_id)
            ->get();

        foreach ($admins as $admin) {
            \Filament\Notifications\Notification::make()
                ->title("Đơn #{$order->code} — Không tìm được tài xế")
                ->body("Đơn từ {$order->pickup_address} đã quá " . self::DISPATCH_TIMEOUT_MINS . " phút không có tài xế nhận. Vui lòng xử lý thủ công.")
                ->danger()
                ->sendToDatabase($admin);
        }

        Log::info("╟── [Dispatch] Đơn #{$order->id}: Không tìm được tài xế sau " . self::DISPATCH_TIMEOUT_MINS . " phút → giữ pending, dừng dispatch");
    }

    public function offerToNext(Order $order, float $radiusKm): void
    {
        $order = $order->fresh();
        if (!$order || $order->status !== 'pending') return;

        Redis::setex($this->radiusKey($order->id), self::QUEUE_TTL_SECS, $radiusKm);

        $alreadyOffered = OrderDispatchLog::where('order_id', $order->id)
            ->pluck('driver_id')
            ->toArray();

        Log::info("┌─ [Dispatch] Đơn #{$order->id} | Scan {$radiusKm}km | Đã hỏi: " . count($alreadyOffered));

        $candidates = $this->getCandidates($order, $radiusKm, $alreadyOffered);

        if ($candidates->isEmpty()) {
            Log::info("└─ [Dispatch] Đơn #{$order->id}: không có ứng viên trong {$radiusKm}km → mở rộng");
            $this->tryExpandRadius($order, $radiusKm);
            return;
        }

        $driver = $candidates->first();
        Log::info("│  Chọn: #{$driver->id} {$driver->name} | " . count($alreadyOffered) . " đã hỏi trước");
        $this->sendToDriver($order, $driver);
    }

    const DISPATCH_TIMEOUT_MINS = 15;

    private function tryExpandRadius(Order $order, float $currentKm): void
    {
        $order = $order->fresh();
        if (!$order || $order->status !== 'pending') return;

        if ($order->dispatch_started_at) {
            $elapsed = (int) abs(now()->diffInMinutes($order->dispatch_started_at));
            if ($elapsed >= self::DISPATCH_TIMEOUT_MINS) {
                Log::info("╟── [Dispatch] Đơn #{$order->id}: Quá {$elapsed} phút không có tài xế → dừng dispatch, giữ pending");
                $this->cancelNoDriver($order);
                return;
            }
        }

        $nextKm = null;
        foreach (self::RADIUS_KM_STAGES as $km) {
            if ($km > $currentKm + 0.001) {
                $nextKm = $km;
                break;
            }
        }

        if ($nextKm === null) {
            // Chống tích lũy RetryJob: chỉ schedule 1 retry tại 1 thời điểm
            if (!Redis::set($this->retryKey($order->id), 1, 'NX', 'EX', 20)) {
                Log::debug("╟── [Dispatch] Đơn #{$order->id}: Retry đã được lên lịch, bỏ qua");
                return;
            }

            $firstKm = self::RADIUS_KM_STAGES[0];
            Log::info("╟── [Dispatch] Đơn #{$order->id}: Hết bán kính ({$currentKm}km) → quét lại {$firstKm}km sau 15s");
            Redis::setex($this->radiusKey($order->id), self::QUEUE_TTL_SECS, $firstKm);

            DispatchOrderRetryJob::dispatch($order->id)->delay(now()->addSeconds(15));
            return;
        }

        Log::info("╟── [Dispatch] Đơn #{$order->id}: [{$currentKm}km] → mở sang {$nextKm}km");
        $this->notifyCustomer($order, 'expanding');
        $this->offerToNext($order->fresh(), $nextKm);
    }


    // =========================================================================
    // PRIVATE — SEND + SCORING
    // =========================================================================

    private function sendToDriver(Order $order, User $driver): void
    {
        // Lock tài xế — chặn 2 dispatch gán cùng lúc
        $lockKey = "dispatch:lock:driver:{$driver->id}";
        if (!Redis::set($lockKey, $order->id, 'NX', 'EX', 60)) {
            Log::debug("│  Skip #{$driver->id} {$driver->name}: đang nhận offer từ dispatch khác");
            $this->sendToNextDriver($order);
            return;
        }

        // Verify lại lần cuối — tài xế có thể nhận đơn giữa lúc pop và lock
        $activeCount = Order::where('delivery_man_id', $driver->id)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->count();
        if ($activeCount >= 2) {
            Redis::del($lockKey);
            Log::debug("│  Skip #{$driver->id} {$driver->name}: đã có {$activeCount} đơn active");
            $this->sendToNextDriver($order);
            return;
        }

        $now  = now();
        $dist = round($this->haversineKm(
            (float) ($driver->latitude ?? 0), (float) ($driver->longitude ?? 0),
            (float) $order->pickup_lat, (float) $order->pickup_lng
        ), 2);

        $ratingCount    = Order::where('delivery_man_id', $driver->id)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->count();

        $scoreScore     = round(($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE) / DriverScoreService::MAX_SCORE * self::W_SCORE, 1);
        $ratingCntScore = round(min($ratingCount, self::RATING_COUNT_CAP) / self::RATING_COUNT_CAP * self::W_RATING_CNT, 1);
        $waitScore      = round($this->waitTimeScore($driver), 1);
        $total          = round($scoreScore + $ratingCntScore + $waitScore, 1);

        Log::info("│");
        Log::info("└→ [Dispatch] GỬI ĐƠN #{$order->id}");
        Log::info("     Tài xế     : #{$driver->id} {$driver->name} | SĐT: {$driver->phone}");
        Log::info("     Khoảng cách: {$dist} km");
        Log::info("     Điểm tổng  : {$total} = score({$scoreScore}) + so_dg({$ratingCntScore}) + wait({$waitScore})");
        Log::info("     driver_score: " . ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE) . " | so_danh_gia: {$ratingCount}");
        Log::info("     FCM token  : " . ($driver->fcm_token ? 'có' : 'KHÔNG CÓ'));

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
            'offer_viewed_at'          => null,
            'updated_at'               => $now,
        ]);
        $order->offer_viewed_at = null;

        $offeredAt = $now->timestamp;
        $expiresAt = $offeredAt + self::DRIVER_OFFER_SECS;
        RTDBService::writeDriverOffer($driver->id, [
            'order_id'          => $order->id,
            'order_code'        => $order->code,
            'offered_at'        => $offeredAt,
            'expires_at'        => $expiresAt,
            'service_type'      => $order->service_type,
            'pickup_address'    => $order->pickup_address    ?? '',
            'pickup_place_name' => $order->pickup_place_name ?? null,
            'pickup_name'       => $order->sender_name       ?? '',
            'pickup_phone'      => $order->pickup_phone      ?? '',
            'pickup_lat'        => $order->pickup_lat        ? (float) $order->pickup_lat  : null,
            'pickup_lng'        => $order->pickup_lng        ? (float) $order->pickup_lng  : null,
            'delivery_address'  => $order->delivery_address  ?? '',
            'delivery_phone'    => $order->delivery_phone    ?? '',
            'receiver_name'     => $order->receiver_name     ?? '',
            'delivery_lat'      => $order->delivery_lat      ? (float) $order->delivery_lat : null,
            'delivery_lng'      => $order->delivery_lng      ? (float) $order->delivery_lng : null,
            'order_note'        => $order->order_note        ?? '',
            'store_name'        => $order->store_name        ?? '',
            'platform'          => $order->platform          ?? 'customer_app',
            'shop_service_type' => $order->shop_service_type ?? null,
            'cargo_type'        => $order->cargo_type        ?? null,
            'cargo_note'        => $order->cargo_note        ?? null,
            'cargo_weight'      => $order->cargo_weight      ? (float) $order->cargo_weight : null,
            'is_batch'          => (bool) ($order->is_batch  ?? false),
            'stops_count'       => $order->is_batch ? count($order->stops ?? []) : 0,
            'stops'             => $order->is_batch ? ($order->stops ?? []) : [],
            'shipping_fee'      => (int) ($order->shipping_fee    ?? 0),
            'discount_amount'   => (int) ($order->discount_amount ?? 0),
            'voucher_code'      => $order->voucher_code      ?? null,
            'bonus_fee'         => (int) ($order->bonus_fee  ?? 0),
            'payment_method'    => $order->payment_method    ?? 'prepaid',
            'cod_amount'        => (int) ($order->cod_amount ?? 0),
            'customer_phone'    => $order->sender?->phone    ?? '',
        ]);
        Log::debug("     → RTDB offer ghi thành công (expires_at: {$expiresAt})");

        if ($driver->fcm_token) {
            try {
                FCMService::getInstance()->sendDriverWakeUp($driver->fcm_token, $order->id, $order->code, $order->pickup_address ?? '');
                Log::debug("     → FCM wake-up gửi thành công");
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM failed for driver #{$driver->id}: " . $e->getMessage());
            }
        }

        DispatchOrderJob::dispatch($order->id, $driver->id)
            ->delay(now()->addSeconds(self::DRIVER_OFFER_SECS));

        broadcast(new DispatchStateChanged());
    }

    public function getCandidates(Order $order, float $radiusKm, array $excludeIds = []): Collection
    {
        if (!$order->city_id) {
            Log::warning("[Dispatch] Đơn #{$order->id} không có city_id → không thể tìm tài xế");
            return collect();
        }

        $now = now();

        // ── 1. Tìm tài xế gần điểm lấy hàng ───────────────────────────────────
        $nearbyDrivers = DriverGeoService::nearby(
            $order->city_id,
            (float) $order->pickup_lat,
            (float) $order->pickup_lng,
            $radiusKm
        );

        // Fallback Haversine khi Redis GEO trống (cold start / Redis restart)
        if (empty($nearbyDrivers)) {
            Log::debug("     [Candidates] Redis GEO trống → fallback Haversine");
            $nearbyDrivers = $this->getNearbyFromDB(
                $order->city_id,
                (float) $order->pickup_lat,
                (float) $order->pickup_lng,
                $radiusKm
            );
        }

        if (empty($nearbyDrivers)) {
            Log::debug("     [Candidates] Không có tài xế nào trong bán kính {$radiusKm}km");
            return collect();
        }

        Log::debug("     [Candidates] " . count($nearbyDrivers) . " tài xế trong bán kính {$radiusKm}km");

        // ── 2. Loại tài xế bận / đang nhận offer khác ────────────────────────────
        $busyDriverIds = Order::selectRaw('delivery_man_id, COUNT(*) as cnt')
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereNotNull('delivery_man_id')
            ->groupBy('delivery_man_id')
            ->havingRaw('cnt >= 2')
            ->pluck('delivery_man_id');

        $receivingOfferIds = Order::where('status', 'pending')
            ->whereNotNull('dispatching_to_driver_id')
            ->where('id', '!=', $order->id)
            ->pluck('dispatching_to_driver_id');

        $unavailableIds = $busyDriverIds->merge($receivingOfferIds)->unique();

        $eligibleIds = array_diff(
            array_keys($nearbyDrivers),
            $excludeIds,
            $unavailableIds->toArray()
        );

        if (empty($eligibleIds)) {
            Log::debug("     [Candidates] Bận: {$busyDriverIds->count()} | Đang nhận offer: {$receivingOfferIds->count()} | Đã hỏi: " . count($excludeIds) . " → không còn ai");
            return collect();
        }

        // ── 3. Query DB: profile, score, debt, license ───────────────────────────
        // Không lọc theo last_location_at — app chỉ gửi GPS khi di chuyển ≥10m,
        // tài xế ngồi yên chờ đơn sẽ bị loại oan nếu lọc theo độ tươi GPS.
        $candidates = User::whereIn('id', $eligibleIds)
            ->where('status', 1)
            ->where('is_online', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('score_suspended_until')
                  ->orWhere('score_suspended_until', '<=', $now);
            })
            ->with(['debts', 'driverLicenses'])
            ->get();

        Log::debug("     [Candidates] Online/active: {$candidates->count()} | Bận: {$busyDriverIds->count()} | Đang nhận offer: {$receivingOfferIds->count()} | Đã hỏi: " . count($excludeIds));

        $afterDebt = $candidates->filter(fn(User $d) => !$this->hasBlockedDebt($d));
        if (($removed = $candidates->count() - $afterDebt->count()) > 0) {
            Log::debug("     [Candidates] Loại {$removed} tài xế do nợ quá hạn");
        }

        $afterLicense = $afterDebt->filter(function (User $d) use ($order) {
            if ($order->service_type === 'car') {
                return $d->has_car_license;
            }
            return true;
        });
        if (($removed = $afterDebt->count() - $afterLicense->count()) > 0) {
            Log::debug("     [Candidates] Loại {$removed} tài xế do không phù hợp loại xe ({$order->service_type})");
        }

        // ── 4. Ghép đơn: chỉ giữ tài xế rảnh HOẶC có 1 đơn mà điểm lấy mới ≤ 1.5km từ điểm giao
        $activeOrders = Order::whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereIn('delivery_man_id', $afterLicense->pluck('id'))
            ->get(['delivery_man_id', 'delivery_lat', 'delivery_lng'])
            ->keyBy('delivery_man_id');

        $afterDetour = $afterLicense->filter(function (User $d) use ($order, $activeOrders) {
            $active = $activeOrders->get($d->id);
            if (!$active) return true;
            if (!$active->delivery_lat || !$active->delivery_lng) return false;
            $pickupToDelivery = $this->haversineKm(
                (float) $order->pickup_lat, (float) $order->pickup_lng,
                (float) $active->delivery_lat, (float) $active->delivery_lng
            );
            return $pickupToDelivery <= 1.5;
        });
        if (($removed = $afterLicense->count() - $afterDetour->count()) > 0) {
            Log::debug("     [Candidates] Loại {$removed} tài xế — điểm lấy mới xa điểm giao đơn đang chạy (>1.5km)");
        }

        // ── 5. Sort theo composite score ──────────────────────────────────────────
        $driverIds   = $afterDetour->pluck('id')->toArray();
        $ratingStats = Order::whereIn('delivery_man_id', $driverIds)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->select('delivery_man_id', DB::raw('COUNT(*) as rating_count'))
            ->groupBy('delivery_man_id')
            ->pluck('rating_count', 'delivery_man_id');

        $sorted = $afterDetour
            ->sortByDesc(function (User $d) use ($ratingStats, $nearbyDrivers) {
                return $this->compositeScore($d, (int) ($ratingStats[$d->id] ?? 0), $nearbyDrivers[$d->id] ?? 0.0);
            })
            ->take(self::MAX_DRIVERS)
            ->values();

        if ($sorted->isNotEmpty()) {
            Log::debug("     [Candidates] Top " . min(5, $sorted->count()) . " tài xế:");
            foreach ($sorted->take(5) as $i => $d) {
                $dist  = round($nearbyDrivers[$d->id] ?? 0.0, 2);
                $cnt   = (int) ($ratingStats[$d->id] ?? 0);
                $score = round($this->compositeScore($d, $cnt, $dist), 1);
                $wait  = round($this->waitTimeScore($d), 1);
                Log::debug("       " . ($i + 1) . ". #{$d->id} {$d->name} | {$dist}km | điểm={$score} | driver_score=" . ($d->driver_score ?? DriverScoreService::DEFAULT_SCORE) . " | so_dg={$cnt} | wait={$wait}");
            }
        }

        return $sorted;
    }

    private function notifyCustomer(Order $order, string $type): void
    {
        $customer = User::find($order->sender_platform_id);
        if (!$customer?->fcm_token) return;

        try {
            $fcm = FCMService::getInstance();
            match ($type) {
                'searching' => $fcm->sendSearchingDriver($customer->fcm_token, $order->code),
                'expanding' => $fcm->sendExpandingSearch($customer->fcm_token, $order->code),
                default     => null,
            };
        } catch (\Throwable $e) {
            Log::error("[Dispatch] notifyCustomer {$type} failed: " . $e->getMessage());
        }
    }

    /**
     * Truy vấn MySQL dùng Haversine — nguồn dữ liệu chính cho dispatch.
     *
     * @return array<int, float> [driverId => distanceKm]
     */
    private function getNearbyFromDB(int $cityId, float $lat, float $lng, float $radiusKm): array
    {
        try {
            $results = DB::table('users')
                ->select('id', DB::raw(
                    "(6371 * acos(
                        cos(radians({$lat})) * cos(radians(latitude)) *
                        cos(radians(longitude) - radians({$lng})) +
                        sin(radians({$lat})) * sin(radians(latitude))
                    )) AS distance_km"
                ))
                ->where('user_type', 'driver')
                ->where('is_online', true)
                ->where('city_id', $cityId)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->having('distance_km', '<=', $radiusKm)
                ->orderBy('distance_km')
                ->limit(100)
                ->get();

            $map = [];
            foreach ($results as $row) {
                $map[(int) $row->id] = (float) $row->distance_km;
            }
            return $map;
        } catch (\Throwable $e) {
            Log::error("[Dispatch] getNearbyFromDB failed: " . $e->getMessage());
            return [];
        }
    }

    private function compositeScore(User $driver, int $ratingCount, float $distanceKm = 0.0): float
    {
        $scoreScore     = ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE) / DriverScoreService::MAX_SCORE * self::W_SCORE;
        $ratingCntScore = min($ratingCount, self::RATING_COUNT_CAP) / self::RATING_COUNT_CAP * self::W_RATING_CNT;
        $waitScore      = $this->waitTimeScore($driver);
        $distScore      = (1 - min($distanceKm, self::MAX_RADIUS_KM) / self::MAX_RADIUS_KM) * self::W_DISTANCE;
        return $scoreScore + $ratingCntScore + $waitScore + $distScore;
    }

    private function waitTimeScore(User $driver): float
    {
        $since = $driver->last_order_completed_at ?? $driver->online_since;
        if (!$since) return 0;
        $waitMins = min(self::WAIT_TIME_CAP_MINS, abs(now()->diffInMinutes(Carbon::parse($since))));
        return ($waitMins / self::WAIT_TIME_CAP_MINS) * self::W_WAIT_TIME;
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
