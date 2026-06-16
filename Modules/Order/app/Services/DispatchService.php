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
use Modules\Core\Models\Voucher;
use Modules\Core\Models\VoucherUsage;
use Modules\Core\Services\DriverGeoService;

class DispatchService
{
    // Bán kính tìm tài xế theo thời gian đã chờ (progressive radius)
    const RADIUS_STAGES = [
        ['after_secs' => 0,   'km' => 1],
        ['after_secs' => 120, 'km' => 2],   // sau 2 phút → mở rộng 2km
        ['after_secs' => 300, 'km' => 3],   // sau 5 phút → mở rộng 3km
    ];

    const DRIVER_OFFER_SECS  = 20;   // 20 giây mỗi tài xế
    const TIMEOUT_SECS       = 600;  // 10 phút → auto-cancel nếu không ai nhận
    const RETRY_SCAN_SECS    = 20;   // không tìm thấy ứng viên nào → quét lại sau 20s
    const FCM_TTL_SECS       = 20;
    const MAX_DRIVERS        = 50;

    // Trọng số xếp hạng trong cùng bán kính
    const W_SCORE         = 60;  // điểm tài xế
    const W_RATING_CNT    = 20;  // số lần được đánh giá (kinh nghiệm)
    const W_WAIT_TIME     = 10;  // chờ lâu chưa có đơn
    const W_DISTANCE      = 10;  // gần điểm lấy hàng hơn

    const WAIT_TIME_CAP_MINS = 60;  // tối đa 60 phút wait bonus
    const RATING_COUNT_CAP   = 200; // chuẩn hóa số đánh giá tối đa 200
    const MAX_RADIUS_KM      = 3.0; // bán kính tối đa để chuẩn hóa khoảng cách

    // Tài xế đang có đơn active chỉ được gợi ý đơn mới nếu không phải đi vòng quá xa
    const MAX_DETOUR_KM = 2.0;

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

        // Ghi thời điểm bắt đầu dispatch để tính progressive radius
        DB::table('orders')->where('id', $order->id)->update(['dispatch_started_at' => $now]);
        $order->dispatch_started_at = $now;

        AutoCancelOrderJob::dispatch($order->id)->delay($now->copy()->addSeconds(self::TIMEOUT_SECS));

        $this->sendToNextDriver($order);
    }

    /**
     * Tìm tài xế tiếp theo (chưa được hỏi) và gửi đơn.
     * Bán kính tìm kiếm mở rộng dần theo thời gian chờ.
     */
    public function sendToNextDriver(Order $order): void
    {
        if ($order->status !== 'pending') return;

        $alreadyOffered = OrderDispatchLog::where('order_id', $order->id)
            ->pluck('driver_id')
            ->toArray();

        $attempt  = count($alreadyOffered) + 1;
        $radiusKm = $this->currentRadius($order);

        Log::debug("┌─ [Dispatch] Đơn #{$order->id} | Lần thử #{$attempt} | Bán kính: {$radiusKm}km");
        if (!empty($alreadyOffered)) {
            $offeredNames = User::whereIn('id', $alreadyOffered)->pluck('name', 'id');
            $offeredStr   = collect($alreadyOffered)->map(fn($id) => '#' . $id . ' ' . ($offeredNames[$id] ?? '?'))->implode(', ');
            Log::debug("│  Đã hỏi: {$offeredStr}");
        }

        $candidates = $this->getCandidates($order, $radiusKm, $alreadyOffered);

        if ($candidates->isEmpty()) {
            // Không huỷ ngay — quét lại sau ít giây để cơ chế mở rộng bán kính
            // (RADIUS_STAGES) và việc tài xế online thêm có cơ hội phát huy.
            // Đơn chỉ thực sự bị huỷ khi AutoCancelOrderJob (TIMEOUT_SECS) kích hoạt.
            Log::info("└─ [Dispatch] Đơn #{$order->id}: Không tìm thấy tài xế (bán kính {$radiusKm}km) → quét lại sau " . self::RETRY_SCAN_SECS . "s");
            RetryDispatchJob::dispatch($order->id)->delay(now()->addSeconds(self::RETRY_SCAN_SECS));
            return;
        }

        Log::debug("│  Tìm được {$candidates->count()} tài xế khả dụng");
        $this->sendToDriver($order, $candidates->first());
    }

    /**
     * Xử lý khi tài xế không phản hồi trong 30 giây.
     */
    public function handleTimeout(Order $order, int $driverId): void
    {
        $driver = User::find($driverId);
        $name   = $driver?->name ?? "#{$driverId}";

        // Kiểm tra log còn pending không — tránh race condition với declineOrder
        $updated = OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', $driverId)
            ->where('result', 'pending')
            ->update(['result' => 'expired', 'responded_at' => now()]);

        // Nếu 0 row được update → driver đã decline trước đó → không xử lý nữa
        if (!$updated) {
            Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} đã xử lý trước (decline) → bỏ qua timeout");
            return;
        }

        DriverScoreService::onTimeout($driverId);
        Log::info("⏱  [Dispatch] Đơn #{$order->id}: Tài xế {$name} timeout → -1 điểm, chuyển sang tài xế tiếp theo");

        // Xóa offer trên RTDB — driver app tự dismiss màn hình offer
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

        // Hoàn lại lượt dùng voucher khi không tìm được tài xế
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

        $attempts = OrderDispatchLog::where('order_id', $order->id)->count();

        Log::info("╔══════════════════════════════════════════════════════════════");
        Log::info("║ [Dispatch] KẾT QUẢ: ĐƠN #{$order->id} ĐƯỢC NHẬN");
        Log::info("║  Tài xế  : #{$driver->id} {$driver->name} | SĐT: {$driver->phone}");
        Log::info("║  Sau lần thử: #{$attempts}");
        Log::info("╚══════════════════════════════════════════════════════════════");
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Tính bán kính hiện tại dựa theo thời gian đã trôi qua kể từ startDispatch.
     */
    private function currentRadius(Order $order): float
    {
        if (!$order->dispatch_started_at) {
            return self::RADIUS_STAGES[0]['km'];
        }

        // Lưu ý: diffInSeconds trả về giá trị có dấu (âm nếu mốc so sánh ở quá khứ),
        // nên phải gọi từ mốc bắt đầu tới hiện tại để elapsed luôn dương.
        $elapsed  = Carbon::parse($order->dispatch_started_at)->diffInSeconds(now());
        $radiusKm = self::RADIUS_STAGES[0]['km'];

        foreach (self::RADIUS_STAGES as $stage) {
            if ($elapsed >= $stage['after_secs']) {
                $radiusKm = $stage['km'];
            }
        }

        return $radiusKm;
    }

    private function sendToDriver(Order $order, User $driver): void
    {
        $now  = now();
        $dist = round($this->distanceKm($driver, $order), 2);

        $ratingCount = Order::where('delivery_man_id', $driver->id)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->count();

        $scoreScore    = round(($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE) / DriverScoreService::MAX_SCORE * self::W_SCORE, 1);
        $ratingCntScore = round(min($ratingCount, self::RATING_COUNT_CAP) / self::RATING_COUNT_CAP * self::W_RATING_CNT, 1);
        $waitScore     = round($this->waitTimeScore($driver), 1);
        $total         = round($scoreScore + $ratingCntScore + $waitScore, 1);

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
            // Reset để job đếm ngược "app-decision" được tạo đúng cho TÀI XẾ MỚI này
            // (offer_viewed_at trước đây ở cấp đơn hàng → tài xế thứ 2 trở đi mở app
            // sẽ không được tạo job riêng, khiến đơn bị treo tới khi auto-cancel 10 phút)
            'offer_viewed_at'          => null,
            'updated_at'               => $now,
        ]);
        $order->offer_viewed_at = null;

        // Ghi offer lên RTDB — driver app nhận qua stream real-time (< 300ms)
        $offeredAt = $now->timestamp;
        $expiresAt = $offeredAt + self::DRIVER_OFFER_SECS;
        RTDBService::writeDriverOffer($driver->id, [
            'order_id'         => $order->id,
            'order_code'       => $order->code,
            'offered_at'       => $offeredAt,
            'expires_at'       => $expiresAt,
            'service_type'     => $order->service_type,
            'pickup_address'    => $order->pickup_address    ?? '',
            'pickup_place_name' => $order->pickup_place_name ?? null,
            'pickup_name'       => $order->sender_name       ?? '',
            'pickup_phone'     => $order->pickup_phone    ?? '',
            'pickup_lat'       => $order->pickup_lat      ? (float) $order->pickup_lat  : null,
            'pickup_lng'       => $order->pickup_lng      ? (float) $order->pickup_lng  : null,
            'delivery_address' => $order->delivery_address ?? '',
            'delivery_phone'   => $order->delivery_phone  ?? '',
            'receiver_name'    => $order->receiver_name   ?? '',
            'delivery_lat'     => $order->delivery_lat    ? (float) $order->delivery_lat : null,
            'delivery_lng'     => $order->delivery_lng    ? (float) $order->delivery_lng : null,
            'order_note'       => $order->order_note      ?? '',
            'store_name'       => $order->store_name      ?? '',
            'platform'          => $order->platform          ?? 'customer_app',
            'shop_service_type' => $order->shop_service_type ?? null,
            'cargo_type'        => $order->cargo_type        ?? null,
            'cargo_note'       => $order->cargo_note      ?? null,
            'cargo_weight'     => $order->cargo_weight    ? (float) $order->cargo_weight : null,
            'is_batch'         => (bool) ($order->is_batch ?? false),
            'stops_count'      => $order->is_batch ? count($order->stops ?? []) : 0,
            'stops'            => $order->is_batch ? ($order->stops ?? []) : [],
            'shipping_fee'     => (int) ($order->shipping_fee   ?? 0),
            'discount_amount'  => (int) ($order->discount_amount ?? 0),
            'voucher_code'     => $order->voucher_code ?? null,
            'bonus_fee'        => (int) ($order->bonus_fee       ?? 0),
            'payment_method'   => $order->payment_method  ?? 'prepaid',
            'cod_amount'       => (int) ($order->cod_amount      ?? 0),
            'customer_phone'   => $order->sender?->phone  ?? '',
        ]);
        Log::debug("     → RTDB offer ghi thành công (expires_at: {$expiresAt})");

        // FCM chỉ để wake-up khi app bị kill — RTDB là nguồn dữ liệu chính
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

        $now = now();

        // ── 1. Query Redis GEO → driver IDs trong bán kính, kèm khoảng cách thực ──
        $nearbyDrivers = DriverGeoService::getNearby(
            $order->city_id,
            (float) $order->pickup_lat,
            (float) $order->pickup_lng,
            $radiusKm
        );
        // $nearbyDrivers = [driverId => distanceKm] — đã lọc GPS stale (TTL 10 phút)

        if (empty($nearbyDrivers)) {
            Log::debug("     [Candidates] Redis GEO: không có tài xế nào trong bán kính {$radiusKm}km");
            return collect();
        }

        Log::debug("     [Candidates] Redis GEO: " . count($nearbyDrivers) . " tài xế trong bán kính {$radiusKm}km");

        // ── 2. Tài xế bận / đang nhận offer ──────────────────────────────────────
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

        // ── 3. Query DB để lấy profile, score, debt, license ─────────────────────
        $candidates = User::whereIn('id', $eligibleIds)
            ->where('status', 1)
            ->where('is_online', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('score_suspended_until')
                  ->orWhere('score_suspended_until', '<=', $now);
            })
            ->with(['debts', 'wallet', 'driverLicenses'])
            ->get();

        Log::debug("     [Candidates] Online/active: {$candidates->count()} | Bận: {$busyDriverIds->count()} | Đang nhận offer: {$receivingOfferIds->count()} | Đã hỏi: " . count($excludeIds));

        $beforeDebt  = $candidates->count();
        $afterDebt   = $candidates->filter(fn(User $d) => !$this->hasBlockedDebt($d));
        $debtRemoved = $beforeDebt - $afterDebt->count();
        if ($debtRemoved > 0) {
            Log::debug("     [Candidates] Loại {$debtRemoved} tài xế do nợ quá hạn");
        }

        $afterLicense = $afterDebt->filter(fn(User $d) => $order->service_type !== 'car' || $d->has_car_license);
        $licRemoved   = $afterDebt->count() - $afterLicense->count();
        if ($licRemoved > 0) {
            Log::debug("     [Candidates] Loại {$licRemoved} tài xế do không có bằng xe hơi");
        }

        // ── 4. Loại tài xế đang bận nếu đi quá vòng ─────────────────────────────
        $activeOrders = Order::whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->whereIn('delivery_man_id', $afterLicense->pluck('id'))
            ->whereNotNull('delivery_lat')
            ->whereNotNull('delivery_lng')
            ->get(['delivery_man_id', 'delivery_lat', 'delivery_lng'])
            ->keyBy('delivery_man_id');

        $afterDetour = $afterLicense->filter(function (User $d) use ($order, $nearbyDrivers, $activeOrders) {
            $active = $activeOrders->get($d->id);
            if (!$active) return true;

            $toPickup = $nearbyDrivers[$d->id]; // khoảng cách thực từ Redis

            $directToDest = $this->haversineKm(
                (float) $d->latitude, (float) $d->longitude,
                (float) $active->delivery_lat, (float) $active->delivery_lng
            );
            $viaPickup = $toPickup + $this->haversineKm(
                (float) $order->pickup_lat, (float) $order->pickup_lng,
                (float) $active->delivery_lat, (float) $active->delivery_lng
            );

            return ($viaPickup - $directToDest) <= self::MAX_DETOUR_KM;
        });
        $detourRemoved = $afterLicense->count() - $afterDetour->count();
        if ($detourRemoved > 0) {
            Log::debug("     [Candidates] Loại {$detourRemoved} tài xế do đi ngược hướng đơn đang giao");
        }

        // ── 5. Sort theo composite score ──────────────────────────────────────────
        $driverIds = $afterDetour->pluck('id')->toArray();

        $ratingStats = Order::whereIn('delivery_man_id', $driverIds)
            ->whereNotNull('driver_rating')
            ->where('status', 'completed')
            ->select('delivery_man_id', DB::raw('COUNT(*) as rating_count'))
            ->groupBy('delivery_man_id')
            ->pluck('rating_count', 'delivery_man_id');

        $sorted = $afterDetour
            ->sortByDesc(function (User $d) use ($ratingStats, $nearbyDrivers) {
                $dist = $nearbyDrivers[$d->id] ?? 0.0;
                return $this->compositeScore($d, (int) ($ratingStats[$d->id] ?? 0), $dist);
            })
            ->take(self::MAX_DRIVERS)
            ->values();

        if ($sorted->isNotEmpty()) {
            Log::debug("     [Candidates] Top " . min(5, $sorted->count()) . " tài xế sẽ được hỏi:");
            foreach ($sorted->take(5) as $i => $d) {
                $dist      = round($nearbyDrivers[$d->id] ?? 0.0, 2);
                $ratingCnt = (int) ($ratingStats[$d->id] ?? 0);
                $score     = round($this->compositeScore($d, $ratingCnt, $dist), 1);
                $distScore = round((1 - min($dist, self::MAX_RADIUS_KM) / self::MAX_RADIUS_KM) * self::W_DISTANCE, 1);
                $wait      = round($this->waitTimeScore($d), 1);
                Log::debug("       " . ($i + 1) . ". #{$d->id} {$d->name} | {$dist}km | điểm={$score} | driver_score=" . ($d->driver_score ?? DriverScoreService::DEFAULT_SCORE) . " | so_dg={$ratingCnt} | wait={$wait} | dist={$distScore}");
            }
        }

        return $sorted;
    }

    /**
     * Xếp hạng: driver_score(60) + rating_count(20) + wait_time(10) + distance(10)
     * Tài xế gần pickup hơn được ưu tiên trong cùng bán kính.
     */
    private function compositeScore(User $driver, int $ratingCount, float $distanceKm = 0.0): float
    {
        $scoreScore     = ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE) / DriverScoreService::MAX_SCORE * self::W_SCORE;
        $ratingCntScore = min($ratingCount, self::RATING_COUNT_CAP) / self::RATING_COUNT_CAP * self::W_RATING_CNT;
        $waitScore      = $this->waitTimeScore($driver);
        $distScore      = (1 - min($distanceKm, self::MAX_RADIUS_KM) / self::MAX_RADIUS_KM) * self::W_DISTANCE;

        return $scoreScore + $ratingCntScore + $waitScore + $distScore;
    }

    /**
     * Bonus tối đa 10 điểm cho tài xế chờ lâu chưa có đơn.
     * online_since reset về null khi tài xế nhận đơn, set lại khi online.
     */
    private function waitTimeScore(User $driver): float
    {
        if (!$driver->online_since) return 0;

        $waitMins = min(self::WAIT_TIME_CAP_MINS, abs(now()->diffInMinutes(Carbon::parse($driver->online_since))));
        return ($waitMins / self::WAIT_TIME_CAP_MINS) * self::W_WAIT_TIME;
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
