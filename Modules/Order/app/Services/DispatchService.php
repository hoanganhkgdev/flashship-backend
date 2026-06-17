<?php
namespace Modules\Order\Services;

use Carbon\Carbon;
use Modules\Driver\Services\DriverScoreService;
use Modules\Order\Jobs\AutoCancelOrderJob;
use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Jobs\RetryDispatchJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\Voucher;
use Modules\Core\Models\VoucherUsage;
use Modules\Core\Services\DriverGeoService;

class DispatchService
{
    // Bán kính mở rộng theo thứ tự — chuyển sang bán kính tiếp theo khi queue cạn,
    // không phụ thuộc thời gian nữa (queue-based thay vì time-based).
    const RADIUS_KM_STAGES = [3.0, 6.0];

    const DRIVER_OFFER_SECS  = 20;   // giây để mở app (trước khi offer_viewed_at set)
    const APP_DECISION_SECS  = 30;   // giây để đọc & quyết định SAU KHI mở app (như ShopeeFood)
    const TIMEOUT_SECS       = 600;  // 10 phút → auto-cancel nếu không ai nhận
    const RETRY_SCAN_SECS    = 20;   // có tài xế trong GEO nhưng đang bận → quét lại sau 20s
    const FCM_TTL_SECS       = 20;
    const MAX_DRIVERS        = 50;
    const QUEUE_TTL_SECS     = 700;  // TTL cache queue > TIMEOUT_SECS

    // Trọng số xếp hạng
    const W_SCORE         = 60;
    const W_RATING_CNT    = 20;
    const W_WAIT_TIME     = 10;
    const W_DISTANCE      = 10;

    const WAIT_TIME_CAP_MINS = 60;
    const RATING_COUNT_CAP   = 200;
    const MAX_RADIUS_KM      = 6.0;
    const MAX_DETOUR_KM      = 2.0;

    // =========================================================================
    // REDIS QUEUE HELPERS
    // =========================================================================

    private function queueKey(int $orderId): string
    {
        return "dispatch:queue:{$orderId}";
    }

    private function radiusKey(int $orderId): string
    {
        return "dispatch:radius:{$orderId}";
    }

    private function getQueue(int $orderId): array
    {
        $data = Redis::get($this->queueKey($orderId));
        return $data ? json_decode($data, true) : [];
    }

    private function putQueue(int $orderId, array $driverIds): void
    {
        Redis::setex($this->queueKey($orderId), self::QUEUE_TTL_SECS, json_encode($driverIds));
    }

    private function getCurrentRadius(int $orderId): float
    {
        return (float) (Redis::get($this->radiusKey($orderId)) ?? self::RADIUS_KM_STAGES[0]);
    }

    private function retryingKey(int $orderId): string
    {
        return "dispatch:retrying:{$orderId}";
    }

    /** Trả về true nếu set thành công (không có job retry nào đang chờ). */
    private function acquireRetryLock(int $orderId): bool
    {
        // NX = set nếu chưa tồn tại, TTL = RETRY_SCAN_SECS + buffer
        return (bool) Redis::set($this->retryingKey($orderId), 1, 'EX', self::RETRY_SCAN_SECS + 5, 'NX');
    }

    private function releaseRetryLock(int $orderId): void
    {
        Redis::del($this->retryingKey($orderId));
    }

    private function clearDispatchCache(int $orderId): void
    {
        Redis::del($this->queueKey($orderId));
        Redis::del($this->radiusKey($orderId));
        Redis::del($this->retryingKey($orderId));
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function startDispatch(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $now = now();

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] BẮT ĐẦU PHÁT ĐƠN");
        Log::info("║  Đơn     : #{$order->id} | Mã: {$order->code}");
        Log::info("║  Loại    : {$order->service_type}");
        Log::info("║  Thành phố: {$order->city_id}");
        Log::info("║  Pickup  : {$order->pickup_address} ({$order->pickup_lat}, {$order->pickup_lng})");
        Log::info("║  Timeout : " . self::TIMEOUT_SECS . "s → auto-cancel nếu không ai nhận");
        Log::info("╚══════════════════════════════════════════════════════════════");

        DB::table('orders')->where('id', $order->id)->update(['dispatch_started_at' => $now]);
        $order->dispatch_started_at = $now;

        $this->notifyCustomer($order, 'searching');

        AutoCancelOrderJob::dispatch($order->id)->delay($now->copy()->addSeconds(self::TIMEOUT_SECS));

        $this->buildQueueAndSend($order, self::RADIUS_KM_STAGES[0]);
    }

    /**
     * Gọi sau khi tài xế từ chối hoặc timeout — pop người tiếp theo từ queue.
     * Không rescan Redis GEO, không delay.
     */
    public function sendToNextDriver(Order $order): void
    {
        if ($order->status !== 'pending') return;
        $this->popAndSend($order);
    }

    /**
     * Gọi bởi RetryDispatchJob — rebuild queue tại bán kính hiện tại
     * (khi lần scan trước không có ứng viên do tất cả đang bận).
     */
    public function retryCurrentRadius(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $hasPendingOffer = OrderDispatchLog::where('order_id', $order->id)
            ->where('result', 'pending')
            ->exists();
        if ($hasPendingOffer) return;

        $this->releaseRetryLock($order->id);

        $radiusKm = $this->getCurrentRadius($order->id);
        Log::info("[Dispatch] Đơn #{$order->id}: Quét lại {$radiusKm}km (tài xế bận đã rảnh?)");
        $this->buildQueueAndSend($order, $radiusKm);
    }

    public function handleTimeout(Order $order, int $driverId): void
    {
        $driver = User::find($driverId);
        $name   = $driver?->name ?? "#{$driverId}";

        $updated = OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', $driverId)
            ->where('result', 'pending')
            ->update(['result' => 'expired', 'responded_at' => now()]);

        if (!$updated) {
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} đã xử lý trước (decline) → bỏ qua timeout");
            return;
        }

        DriverScoreService::onTimeout($driverId);
        Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} timeout → -1 điểm, pop tài xế tiếp theo");

        RTDBService::clearDriverOffer($driverId);

        $this->sendToNextDriver($order->fresh());
    }

    public function cancelIfNoDriver(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $logs      = OrderDispatchLog::where('order_id', $order->id)->get();
        $driverIds = $logs->pluck('driver_id')->unique()->toArray();
        $names     = User::whereIn('id', $driverIds)->pluck('name', 'id');

        OrderDispatchLog::where('order_id', $order->id)
            ->where('result', 'pending')
            ->update(['result' => 'expired', 'responded_at' => now()]);

        $cancelled = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'cancel_reason' => 'no_driver', 'updated_at' => now()]);

        if (!$cancelled) return;

        $this->clearDispatchCache($order->id);

        if ($order->voucher_code) {
            Voucher::where('code', $order->voucher_code)->decrement('used_count');
            VoucherUsage::where('order_id', $order->id)->delete();
            Log::info("║  Hoàn voucher: {$order->voucher_code}");
        }

        $customer = User::find($order->sender_platform_id);
        if ($customer?->fcm_token) {
            FCMService::getInstance()->sendNoDriverCancellation($customer->fcm_token, $order->code);
        }

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] KẾT QUẢ: ĐƠN #{$order->id} BỊ HỦY - Không có tài xế nhận");
        Log::info("║  Tổng số lần thử: {$logs->count()}");
        foreach ($logs as $l) {
            $n = $names[$l->driver_id] ?? '?';
            Log::info("║    - #{$l->driver_id} {$n} → {$l->result}");
        }
        Log::info("╚══════════════════════════════════════════════════════════════");
    }

    public function handleAccepted(Order $order, User $driver): void
    {
        OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->where('result', 'pending')
            ->update(['result' => 'accepted', 'responded_at' => now()]);

        RTDBService::clearDriverOffer($driver->id);
        $this->clearDispatchCache($order->id);

        $attempts = OrderDispatchLog::where('order_id', $order->id)->count();

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] KẾT QUẢ: ĐƠN #{$order->id} ĐƯỢC NHẬN");
        Log::info("║  Tài xế  : #{$driver->id} {$driver->name} | SĐT: {$driver->phone}");
        Log::info("║  Sau lần thử: #{$attempts}");
        Log::info("╚══════════════════════════════════════════════════════════════");
    }

    // =========================================================================
    // PRIVATE — QUEUE CONTROL FLOW
    // =========================================================================

    /**
     * Scan tài xế tại bán kính cho trước → xây queue → phát đơn đầu tiên.
     *
     * Nếu 0 ứng viên:
     *   GEO trống  → expand bán kính ngay (không ai gần đó)
     *   GEO có người nhưng bị filter (bận/đang nhận offer) → retry sau RETRY_SCAN_SECS
     */
    private function buildQueueAndSend(Order $order, float $radiusKm): void
    {
        $alreadyOffered = OrderDispatchLog::where('order_id', $order->id)
            ->pluck('driver_id')
            ->toArray();

        Log::info("┌─ [Dispatch] Đơn #{$order->id} | Scan {$radiusKm}km | Đã hỏi: " . count($alreadyOffered));

        $candidates = $this->getCandidates($order, $radiusKm, $alreadyOffered);

        if ($candidates->isEmpty()) {
            $hasCoords = $order->pickup_lat && $order->pickup_lng;
            Redis::setex($this->radiusKey($order->id), self::QUEUE_TTL_SECS, $radiusKm);

            if (!$hasCoords) {
                Log::info("└─ [Dispatch] Đơn #{$order->id}: không có toạ độ → mở rộng ngay");
                $this->tryExpandRadius($order, $radiusKm);
                return;
            }

            $geoDriverIds = array_keys(DriverGeoService::getNearby(
                $order->city_id, (float) $order->pickup_lat, (float) $order->pickup_lng, $radiusKm
            ));

            // Driver chưa được hỏi lần nào trong bán kính này
            $freshCount = count(array_diff($geoDriverIds, $alreadyOffered));

            if (empty($geoDriverIds) || $freshCount === 0) {
                // GEO trống hoặc đã hỏi hết tất cả → mở rộng ngay
                Log::info("└─ [Dispatch] Đơn #{$order->id}: GEO có " . count($geoDriverIds) . " tài xế, fresh={$freshCount} → mở rộng ngay");
                $this->tryExpandRadius($order, $radiusKm);
            } else {
                // Còn driver chưa hỏi nhưng đang bận/nhận offer khác → chờ họ rảnh
                if ($this->acquireRetryLock($order->id)) {
                    Log::info("└─ [Dispatch] Đơn #{$order->id}: GEO có {$freshCount} tài xế chưa hỏi đang bận → retry sau " . self::RETRY_SCAN_SECS . "s");
                    RetryDispatchJob::dispatch($order->id)->delay(now()->addSeconds(self::RETRY_SCAN_SECS));
                } else {
                    Log::info("└─ [Dispatch] Đơn #{$order->id}: retry job đã tồn tại → bỏ qua");
                }
            }
            return;
        }

        $driverIds = $candidates->pluck('id')->toArray();
        $this->putQueue($order->id, $driverIds);
        Redis::setex($this->radiusKey($order->id), self::QUEUE_TTL_SECS, $radiusKm);

        $preview = implode(', ', array_map(fn($id) => "#{$id}", array_slice($driverIds, 0, 5)));
        Log::info("│  Queue {$radiusKm}km: " . count($driverIds) . " tài xế → [{$preview}" . (count($driverIds) > 5 ? '...' : '') . "]");

        $this->popAndSend($order);
    }

    /**
     * Pop tài xế đầu queue → kiểm tra còn khả dụng không → gửi offer.
     * Bỏ qua tài xế offline/bận → pop tiếp (không cần rescan).
     * Queue cạn → mở rộng bán kính.
     */
    private function popAndSend(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $queue   = $this->getQueue($order->id);
        $skipped = 0;

        while (!empty($queue)) {
            $driverId = array_shift($queue);

            // Kiểm tra nhanh: còn online & active không?
            $driver = User::where('id', $driverId)
                ->where('is_online', true)
                ->where('status', 1)
                ->where(fn($q) => $q->whereNull('score_suspended_until')->orWhere('score_suspended_until', '<=', now()))
                ->first();

            if (!$driver) {
                $skipped++;
                continue;
            }

            // Driver không có FCM token → không thể đánh thức, skip ngay
            if (!$driver->fcm_token) {
                Log::debug("│  Skip #{$driverId}: không có FCM token");
                $skipped++;
                continue;
            }

            $busy = Order::where('delivery_man_id', $driverId)
                ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
                ->count() >= 2;
            if ($busy) {
                $skipped++;
                continue;
            }

            $receivingOther = Order::where('status', 'pending')
                ->where('dispatching_to_driver_id', $driverId)
                ->where('id', '!=', $order->id)
                ->exists();
            if ($receivingOther) {
                $skipped++;
                continue;
            }

            if ($skipped > 0) {
                Log::debug("│  Bỏ qua {$skipped} tài xế không còn khả dụng");
            }

            $this->putQueue($order->id, $queue);
            $this->sendToDriver($order, $driver);
            return;
        }

        // Queue cạn hoàn toàn
        if ($skipped > 0) {
            Log::debug("│  Bỏ qua {$skipped} tài xế, queue rỗng");
        }
        $this->putQueue($order->id, []);

        $currentKm = $this->getCurrentRadius($order->id);
        Log::info("│  Queue {$currentKm}km đã cạn → mở rộng bán kính");
        $this->tryExpandRadius($order, $currentKm);
    }

    /**
     * Tìm bán kính tiếp theo trong RADIUS_KM_STAGES và scan mới.
     * Nếu đã hết tất cả bán kính → chờ AutoCancelOrderJob.
     */
    private function tryExpandRadius(Order $order, float $currentKm): void
    {
        $nextKm = null;
        foreach (self::RADIUS_KM_STAGES as $km) {
            if ($km > $currentKm + 0.001) {
                $nextKm = $km;
                break;
            }
        }

        if ($nextKm === null) {
            Log::info("╟── [Dispatch] Đơn #{$order->id}: Đã hết tất cả bán kính ({$currentKm}km) → cancel ngay");
            $this->cancelIfNoDriver($order->fresh());
            return;
        }

        Log::info("╟── [Dispatch] Đơn #{$order->id}: [{$currentKm}km] → mở sang {$nextKm}km");
        $this->notifyCustomer($order, 'expanding');
        $this->buildQueueAndSend($order->fresh(), $nextKm);
    }

    // =========================================================================
    // PRIVATE — SEND + SCORING
    // =========================================================================

    private function sendToDriver(Order $order, User $driver): void
    {
        $now  = now();
        $dist = round($this->distanceKm($driver, $order), 2);

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
                FCMService::getInstance()->sendDriverWakeUp($driver->fcm_token, $order->id);
                Log::debug("     → FCM wake-up gửi thành công");
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM failed for driver #{$driver->id}: " . $e->getMessage());
            }
        }

        DispatchOrderJob::dispatch($order->id, $driver->id)
            ->delay(now()->addSeconds(self::DRIVER_OFFER_SECS));
    }

    public function getCandidates(Order $order, float $radiusKm, array $excludeIds = []): Collection
    {
        if (!$order->city_id) {
            Log::warning("[Dispatch] Đơn #{$order->id} không có city_id → không thể tìm tài xế");
            return collect();
        }

        $now       = now();
        $hasCoords = $order->pickup_lat && $order->pickup_lng;

        // ── 1. Tìm tài xế gần điểm lấy hàng (hoặc toàn city nếu không có tọa độ) ──
        if ($hasCoords) {
            $nearbyDrivers = DriverGeoService::getNearby(
                $order->city_id,
                (float) $order->pickup_lat,
                (float) $order->pickup_lng,
                $radiusKm
            );

            if (empty($nearbyDrivers)) {
                // DB fallback — GEO trống có thể do driver vừa online chưa kịp gửi GPS lần đầu
                $nearbyDrivers = $this->getNearbyFromDB($order->city_id, (float) $order->pickup_lat, (float) $order->pickup_lng, $radiusKm);
                if (empty($nearbyDrivers)) {
                    Log::debug("     [Candidates] GEO + DB fallback: không có tài xế nào trong bán kính {$radiusKm}km");
                    return collect();
                }
                Log::debug("     [Candidates] GEO trống → DB fallback: " . count($nearbyDrivers) . " tài xế trong {$radiusKm}km");
            } else {
                Log::debug("     [Candidates] Redis GEO: " . count($nearbyDrivers) . " tài xế trong bán kính {$radiusKm}km");
            }
        } else {
            $allDriverIds  = User::where('user_type', 'driver')
                ->where('is_online', true)
                ->where('city_id', $order->city_id)
                ->pluck('id')
                ->toArray();
            $nearbyDrivers = array_fill_keys($allDriverIds, 0.0);
            Log::debug("     [Candidates] Không có tọa độ → lấy toàn city: " . count($nearbyDrivers) . " tài xế");
        }

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

        $afterLicense = $afterDebt->filter(fn(User $d) => $order->service_type !== 'car' || $d->has_car_license);
        if (($removed = $afterDebt->count() - $afterLicense->count()) > 0) {
            Log::debug("     [Candidates] Loại {$removed} tài xế do không có bằng xe hơi");
        }

        // ── 4. Loại tài xế đang bận nếu đi quá vòng ─────────────────────────────
        $activeOrders = Order::whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereIn('delivery_man_id', $afterLicense->pluck('id'))
            ->whereNotNull('delivery_lat')
            ->whereNotNull('delivery_lng')
            ->get(['delivery_man_id', 'delivery_lat', 'delivery_lng'])
            ->keyBy('delivery_man_id');

        $afterDetour = $afterLicense->filter(function (User $d) use ($order, $nearbyDrivers, $activeOrders, $hasCoords) {
            if (!$hasCoords) return true;
            $active = $activeOrders->get($d->id);
            if (!$active) return true;
            $toPickup     = $nearbyDrivers[$d->id];
            $directToDest = $this->haversineKm((float) $d->latitude, (float) $d->longitude, (float) $active->delivery_lat, (float) $active->delivery_lng);
            $viaPickup    = $toPickup + $this->haversineKm((float) $order->pickup_lat, (float) $order->pickup_lng, (float) $active->delivery_lat, (float) $active->delivery_lng);
            return ($viaPickup - $directToDest) <= self::MAX_DETOUR_KM;
        });
        if (($removed = $afterLicense->count() - $afterDetour->count()) > 0) {
            Log::debug("     [Candidates] Loại {$removed} tài xế do đi ngược hướng đơn đang giao");
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
     * Fallback khi Redis GEO trống — truy vấn DB dùng Haversine.
     * Dành cho tài xế vừa online chưa kịp gửi GPS lần đầu.
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
                ->where('updated_at', '>=', now()->subHours(2))
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
        if (!$driver->online_since) return 0;
        $waitMins = min(self::WAIT_TIME_CAP_MINS, abs(now()->diffInMinutes(Carbon::parse($driver->online_since))));
        return ($waitMins / self::WAIT_TIME_CAP_MINS) * self::W_WAIT_TIME;
    }

    private function distanceKm(User $driver, Order $order): float
    {
        return $this->haversineKm((float) $driver->latitude, (float) $driver->longitude, (float) $order->pickup_lat, (float) $order->pickup_lng);
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
